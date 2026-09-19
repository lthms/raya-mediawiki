{{- define "mw.name" -}}
{{- .Values.id -}}
{{- end -}}
{{- define "mw.db" -}}
{{- default (.Values.id | replace "-" "_") .Values.database.name -}}
{{- end -}}
{{- define "mw.appSecret" -}}
{{- default (printf "%s-app" (include "mw.name" .)) .Values.appSecret -}}
{{- end -}}
{{- define "mw.image" -}}
{{- printf "%s@%s" .Values.image.repository .Values.image.digest -}}
{{- end -}}
{{- define "mw.revision" -}}
{{- printf "%s%s" (.Values | toJson) (.Files.Get "files/LocalSettings.php") | sha256sum | trunc 10 -}}
{{- end -}}
{{- define "mw.env" -}}
- name: MW_SITE_NAME
  value: {{ .Values.title | quote }}
- name: MW_SERVER
  value: {{ printf "https://%s" .Values.hostname | quote }}
- name: MW_LANGUAGE
  value: {{ .Values.language | quote }}
- name: MW_TIMEZONE
  value: {{ .Values.timezone | quote }}
{{- end -}}
{{- define "mw.backupEnv" -}}
- name: RESTIC_CACHE_DIR
  value: /tmp/restic-cache
- name: RESTIC_PASSWORD
  valueFrom:
    secretKeyRef:
      name: mediawiki-r2
      key: RESTIC_PASSWORD
- name: MW_BACKUP_ENDPOINT
  valueFrom:
    configMapKeyRef:
      name: mediawiki-backup
      key: endpoint
- name: MW_BACKUP_BUCKET
  valueFrom:
    configMapKeyRef:
      name: mediawiki-backup
      key: bucket
- name: RESTIC_REPOSITORY
  value: {{ printf "s3:$(MW_BACKUP_ENDPOINT)/$(MW_BACKUP_BUCKET)/%s" .Values.id | quote }}
- name: MW_BACKUP_HOST
  value: {{ .Values.id | quote }}
- name: MW_BACKUP_KEEP_DAILY
  value: {{ .Values.backup.keepDaily | quote }}
{{- end -}}
{{- define "mw.jobRevision" -}}
{{- printf "%s-%d" (include "mw.revision" .) .Release.Revision | sha256sum | trunc 10 -}}
{{- end -}}
