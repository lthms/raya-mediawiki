"""Render the chart locally; no cluster, network, or decrypted Secrets required."""
import argparse
import copy
from pathlib import Path
import subprocess
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
CHART = ROOT / "charts/mediawiki"
LEGACY = yaml.safe_load((ROOT / "tests/fixtures/values.yaml").read_text())
SECOND = {
    "id": "family-notes",
    "title": "Notes de famille",
    "hostname": "notes.example.org",
    "backup": {"schedule": "0 5 * * *"},
}


def render(values, check=True):
    return subprocess.run(
        ["helm", "template", values.get("id", "test"), str(CHART),
         "--namespace", "cloud-lab", "--values", "-"],
        input=yaml.safe_dump(values), text=True, capture_output=True, check=check,
    )


def documents(values):
    return [d for d in yaml.safe_load_all(render(values).stdout) if d]


def one(docs, kind):
    return next(d for d in docs if d["kind"] == kind)


def pods(docs):
    for doc in docs:
        if doc["kind"] in ("Deployment", "Job"):
            yield doc["spec"]["template"]["spec"]
        elif doc["kind"] == "CronJob":
            yield doc["spec"]["jobTemplate"]["spec"]["template"]["spec"]


def env(container):
    return {e["name"]: e for e in container.get("env", [])}


class ChartTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.first = documents(LEGACY)
        cls.second = documents(SECOND)

    def test_instances_are_isolated(self):
        identities = lambda ds: {(d["kind"], d["metadata"]["name"]) for d in ds}
        self.assertFalse(identities(self.first) & identities(self.second))
        for docs, name, db, app, backup in [
            (self.first, "example", "mediawiki", "mediawiki-app", "mediawiki-r2"),
            (self.second, "family-notes", "family_notes", "family-notes-app", "mediawiki-r2"),
        ]:
            deploy = one(docs, "Deployment")
            labels = deploy["spec"]["template"]["metadata"]["labels"]
            self.assertEqual(labels, {"app": name})
            self.assertEqual(deploy["spec"]["selector"]["matchLabels"], labels)
            self.assertEqual(one(docs, "Service")["spec"]["selector"], labels)
            cron = one(docs, "CronJob")["spec"]
            pod = cron["jobTemplate"]["spec"]["template"]["spec"]
            affinity = pod["affinity"]["podAffinity"]["requiredDuringSchedulingIgnoredDuringExecution"]
            self.assertEqual(affinity[0]["labelSelector"]["matchLabels"], labels)
            self.assertEqual(one(docs, "Postgres")["spec"]["database"], db)
            self.assertEqual(one(docs, "PostgresUser")["spec"]["role"], db)
            self.assertEqual(one(docs, "PostgresUser")["spec"]["database"], name)
            self.assertFalse(one(docs, "Postgres")["spec"]["dropOnDelete"])
            for kind in ("Postgres", "PostgresUser", "PersistentVolumeClaim"):
                self.assertEqual(one(docs, kind)["metadata"]["annotations"]["helm.sh/resource-policy"], "keep")
            for p in pods(docs):
                for volume in p.get("volumes", []):
                    if "persistentVolumeClaim" in volume:
                        self.assertEqual(volume["persistentVolumeClaim"]["claimName"], name + "-uploads")
                    if "configMap" in volume:
                        self.assertEqual(volume["configMap"]["name"], name + "-settings")
                for c in p.get("containers", []) + p.get("initContainers", []):
                    for ref in c.get("envFrom", []):
                        self.assertIn(ref["secretRef"]["name"],
                                      {"db-" + name, app, backup})
                    for e in env(c).values():
                        if "secretKeyRef" in e.get("valueFrom", {}):
                            self.assertIn(e["valueFrom"]["secretKeyRef"]["name"], {app, backup})
            ingress = one(docs, "Ingress")["spec"]
            self.assertEqual(ingress["rules"][0]["http"]["paths"][0]["backend"]["service"]["name"], name)
            self.assertEqual(ingress["tls"][0]["secretName"], name + "-tls")

    def test_repository_uses_id_and_defaults(self):
        backup = one(self.first, "CronJob")["spec"]
        self.assertEqual(backup["schedule"], "0 4 * * *")
        self.assertEqual(backup["timeZone"], "Europe/Paris")
        self.assertFalse(backup["suspend"])
        e = env(backup["jobTemplate"]["spec"]["template"]["spec"]["containers"][0])
        for pod in pods(self.first):
            for container in pod["containers"]:
                repository = env(container).get("RESTIC_REPOSITORY")
                if repository:
                    self.assertEqual(env(container)["MW_BACKUP_HOST"]["value"], "example")
                    self.assertEqual(repository, {
                        "name": "RESTIC_REPOSITORY",
                        "value": "s3:$(MW_BACKUP_ENDPOINT)/$(MW_BACKUP_BUCKET)/example",
                    })
        self.assertEqual(e["MW_BACKUP_HOST"]["value"], "example")
        self.assertEqual(e["MW_BACKUP_KEEP_DAILY"]["value"], "30")

    def test_new_repositories_share_bucket_but_not_id(self):
        urls = []
        for wiki_id in ("alpha", "bravo"):
            values = dict(SECOND, id=wiki_id)
            docs = documents(values)
            for p in pods(docs):
                for c in p["containers"]:
                    e = env(c)
                    if "RESTIC_REPOSITORY" in e:
                        url = e["RESTIC_REPOSITORY"]["value"]
                        self.assertEqual(url, "s3:$(MW_BACKUP_ENDPOINT)/$(MW_BACKUP_BUCKET)/" + wiki_id)
                        self.assertEqual(e["MW_BACKUP_HOST"]["value"], wiki_id)
                        self.assertEqual(e["RESTIC_PASSWORD"]["valueFrom"]["secretKeyRef"]["name"],
                                         "mediawiki-r2")
                        self.assertIn({"secretRef": {"name": "mediawiki-r2"}}, c["envFrom"])
                        urls.append(url)
        self.assertEqual(len(set(urls)), 2)
        self.assertEqual(len({url.rsplit("/", 1)[0] for url in urls}), 1)

    def test_one_image_and_identity_in_web_worker_installer(self):
        for values, docs in ((LEGACY, self.first), (SECOND, self.second)):
            images = set()
            for p in pods(docs):
                for c in p.get("containers", []) + p.get("initContainers", []):
                    images.add(c["image"])
                    if c["name"] in ("web", "jobs", "initialize"):
                        e = env(c)
                        self.assertEqual(e["MW_SITE_NAME"]["value"], values["title"])
                        self.assertEqual(e["MW_SERVER"]["value"], "https://" + values["hostname"])
                        self.assertEqual(e["MW_LANGUAGE"]["value"], "fr")
                    if c["name"] in ("web", "jobs"):
                        self.assertNotIn("MW_ADMIN_PASSWORD", env(c))
                        self.assertEqual(len(c["envFrom"]), 1)
                    if c["name"] == "initialize":
                        self.assertEqual(c["volumeMounts"][0]["mountPath"], "/etc/mediawiki/LocalSettings.php")
            self.assertEqual(len(images), 1)
            self.assertIn("@sha256:", images.pop())

    def test_jobs_change_names_on_configuration_or_image_changes(self):
        old_jobs = {d["metadata"]["name"] for d in self.first if d["kind"] == "Job"}
        for change in ({"title": "New title"}, {"image": {"digest": "sha256:" + "a" * 64}}):
            values = copy.deepcopy(LEGACY)
            values.update(change)
            docs = documents(values)
            new_jobs = {d["metadata"]["name"] for d in docs if d["kind"] == "Job"}
            self.assertFalse(old_jobs & new_jobs)
            self.assertEqual(one(docs, "Deployment")["metadata"]["name"], "example")
            self.assertNotEqual(
                one(docs, "Deployment")["spec"]["template"]["metadata"]["annotations"],
                one(self.first, "Deployment")["spec"]["template"]["metadata"]["annotations"],
            )

    def test_raya_infrastructure(self):
        self.assertEqual(one(self.first, "PersistentVolumeClaim")["spec"]["storageClassName"],
                         "hcloud-volumes")
        ingress = one(self.first, "Ingress")
        self.assertEqual(ingress["spec"]["ingressClassName"], "traefik")
        self.assertEqual(ingress["metadata"]["annotations"]["cert-manager.io/cluster-issuer"],
                         "letsencrypt")

    def test_shared_backend_references_and_expansion_order(self):
        for docs in (self.first, self.second):
            self.assertFalse(any(d["kind"] == "Secret" for d in docs))
            for pod in pods(docs):
                for container in pod["containers"]:
                    e = env(container)
                    if "RESTIC_REPOSITORY" not in e:
                        continue
                    names = list(e)
                    for variable, key in (("MW_BACKUP_ENDPOINT", "endpoint"),
                                          ("MW_BACKUP_BUCKET", "bucket")):
                        self.assertEqual(e[variable]["valueFrom"]["configMapKeyRef"],
                                         {"name": "mediawiki-backup", "key": key})
                        self.assertLess(names.index(variable), names.index("RESTIC_REPOSITORY"))

    def test_invalid_values_fail_before_render(self):
        for change in ({"id": "../bad"}, {"hostname": "https://bad.example"},
                       {"backup": {"keepDaily": 0}}, {"image": {"digest": "latest"}},
                       {"backupSecret": "old"}, {"backup": {"endpoint": "https://old.example"}},
                       {"backup": {"bucket": "old"}}, {"backup": {"credentialsSecret": "old"}},
                       {"typo": True}, {"title": ""}, {"fullnameOverride": "old"},
                       {"storage": {"className": "other"}}, {"ingress": {"issuer": "other"}}, {"backup": {"scope": "example"}}):
            values = copy.deepcopy(LEGACY)
            values.update(change)
            self.assertNotEqual(render(values, check=False).returncode, 0)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--render-dir", type=Path)
    args, remaining = parser.parse_known_args()
    if args.render_dir:
        # CI passes these exact fixtures through Raya's Kubernetes/CRD schemas too.
        args.render_dir.mkdir(parents=True, exist_ok=True)
        (args.render_dir / "manifests.yaml").write_text(
            render(LEGACY).stdout + "\n---\n" + render(SECOND).stdout
        )
        (args.render_dir / "kustomization.yaml").write_text(
            "apiVersion: kustomize.config.k8s.io/v1beta1\n"
            "kind: Kustomization\nresources:\n  - manifests.yaml\n"
        )
    unittest.main(argv=[__file__, *remaining])
