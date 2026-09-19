<?php
// Reproducible application settings; credentials come from Kubernetes Secrets.
if (!defined('MEDIAWIKI')) {
    exit;
}
$required = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '' || str_contains($value, 'CHANGE_ME')) {
        throw new RuntimeException("Missing or placeholder setting: $name");
    }
    return $value;
};
$wgSitename = $required('MW_SITE_NAME');
$wgMetaNamespace = str_replace(' ', '_', $wgSitename);
$wgServer = $required('MW_SERVER');
$wgCanonicalServer = $wgServer;
$wgScriptPath = '';
$wgArticlePath = '/index.php?title=$1';
$wgForceHTTPS = true;
$wgLanguageCode = $required('MW_LANGUAGE');
$wgLocaltimezone = $required('MW_TIMEZONE');
$wgDBtype = 'postgres';
$wgDBserver = $required('HOSTNAME');
$wgDBport = (int)$required('PORT');
$wgDBname = $required('DATABASE_NAME');
$wgDBuser = $required('LOGIN');
$wgDBpassword = $required('PASSWORD');
$wgDBmwschema = 'mediawiki';
$wgSecretKey = $required('MW_SECRET_KEY');
$wgUpgradeKey = $required('MW_UPGRADE_KEY');
$wgMainCacheType = CACHE_ACCEL;
$wgSessionCacheType = CACHE_DB;
$wgJobRunRate = 0; // The worker runs jobs with the same settings.
$wgReadOnlyFile = '/data/control/read-only';
// Hold a shared lock for the entire request/maintenance process. Backups take
// the exclusive lock, so their database recovery point matches stable uploads.
$mwOperationLock = fopen('/data/control/operations.lock', 'c');
if ($mwOperationLock === false || !flock($mwOperationLock, LOCK_SH | LOCK_NB)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        header('Retry-After: 120');
    }
    exit("Sauvegarde en cours. Réessayez dans quelques instants.\n");
}
register_shutdown_function(static function () use ($mwOperationLock): void {
    flock($mwOperationLock, LOCK_UN);
    fclose($mwOperationLock);
});

$wgEnableEmail = false;
$wgEnableUserEmail = false;
$wgEmailAuthentication = false;
$wgEnotifUserTalk = false;
$wgEnotifWatchlist = false;
$wgCookieExpiration = 30 * 24 * 60 * 60;
$wgExtendedLoginCookieExpiration = 30 * 24 * 60 * 60;
$wgCookieSecure = true;
$wgCookieHttpOnly = true;

// Rights are additive: remove all default account-creation grants.
foreach ($wgGroupPermissions as &$permissions) {
    $permissions['createaccount'] = false;
    $permissions['userrights'] = false;
}
unset($permissions);
$wgGroupPermissions['*']['read'] = false;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['*']['createtalk'] = false;
$wgGroupPermissions['user']['read'] = true;
$wgGroupPermissions['user']['edit'] = true;
$wgGroupPermissions['user']['createpage'] = true;
$wgGroupPermissions['user']['createtalk'] = true;
$wgGroupPermissions['user']['upload'] = true;
$wgGroupPermissions['user']['reupload'] = true;
// Every invited account can manage wiki content, without account administration.
foreach (['move', 'move-subpages', 'move-categorypages', 'move-rootuserpages', 'movefile',
          'delete', 'undelete', 'deletedhistory', 'deletedtext', 'browsearchive',
          'protect', 'editprotected', 'editsemiprotected', 'rollback'] as $right) {
    $wgGroupPermissions['user'][$right] = true;
}
unset($right);
$wgGroupPermissions['accountcreator']['createaccount'] = true;
// Only CLI administration can grant groups; initial admin is the sole member.
$wgAddGroups = [];
$wgRemoveGroups = [];
$wgGroupsAddToSelf = [];
$wgGroupsRemoveFromSelf = [];
$wgWhitelistRead = ['Special:Userlogin'];
$wgBlockDisablesLogin = true;

$wgEnableUploads = true;
$wgUploadDirectory = '/data/uploads';
$wgUploadPath = $wgScriptPath . '/img_auth.php';
$wgFileExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
$wgStrictFileExtensions = true;
$wgMaxUploadSize = 25 * 1024 * 1024;
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = '/usr/bin/convert';
$wgImgAuthDetails = false;

wfLoadSkin('Vector');
$wgDefaultSkin = 'vector-2022';
wfLoadExtension('VisualEditor');
wfLoadExtension('TemplateData');
wfLoadExtension('PdfHandler');
$wgDefaultUserOptions['visualeditor-enable'] = 1;
$wgDefaultUserOptions['visualeditor-editor'] = 'visualeditor';
$wgDefaultUserOptions['visualeditor-edittab'] = 'visualeditor';
$wgVisualEditorAvailableNamespaces = [0 => true];
$wgPdfProcessor = '/usr/bin/gs';
$wgPdfPostProcessor = '/usr/bin/convert';
$wgPdfInfo = '/usr/bin/pdfinfo';
$wgPdftoText = '/usr/bin/pdftotext';
