<?php
/**
 * BareDeployer is PHP app deployer for poor shared hosting solutions 
 * 
 * @license MIT License, please read the LICENSE file
 * @copyright 2025 Samuele Catuzzi
 */

const APP_NAME = 'amministrazione'; // this will be used to compute the uploaded file name ( APP_NAME-APP_VER.zip )
const PASSWORD = 'super-secret'; // the password to protect this script

const PACKAGE_DIR = __DIR__ . '/UPLOAD'; // directory where the uploaded tarball file is ( without the ending / )
const EXTRACT_TO_DIR = __DIR__ . '/EXTRACT'; // base directory where the sub directory APP_NAME-APP_VER will contain the extracted files
const RUNNING_WEBSITE_ROOT_DIR = ''; // this is the root directory where the website is currently running

const SEMVER_REGEX = '/^(?<version>[0-9]+\.[0-9]+\.[0-9]+)(?<prerelease>-[0-9a-zA-Z.]+)?(?<build>\+[0-9a-zA-Z.]+)?$/';

const HASH_ALGORYTHM = 'xxh128'; // note: xxh128 is available from PHP 8.1 and is way faster than sha256 although it is not good for password and sensitive data
const HASH_VALIDATION = '/^([a-f0-9]{32})$/'; // 32 characters for xxh128
const HASH_CHUNK_SIZE = 1024*10; // the size in bytes of a single chunk when hashing the file

// ------- additional internal settings - START
const SAMDEPLOYER_VERSION = '1.0.0';
const COMMANDS = [
    'info' => 'get useful informations about the server and its limitations', 
    'extract' => 'extract only the archive', 
    'deploy' => 'deploy only the extracted app', 
    'extract-and-deploy' => 'execute both extract and deploy',
];
// ------- additional internal settings - END

$scriptStartTime = microtime(true); // in seconds
$eventsTracker = [];

function exitWithError(array $messages, $code = 0): void 
{
    header('Content-Type: application/json; charset=utf-8');
    $msg = new \stdClass();
    $msg->type = "error";
    $msg->code = $code;
    $msg->messages = $messages;
    die(json_encode($msg));
}

function exitWithSuccess(array $messages): void
{
    header('Content-Type: application/json; charset=utf-8');
    $msg = new \stdClass();
    $msg->type = "info";
    $msg->messages = $messages;
    die(json_encode($msg));
}

function elapsedTime(): float
{
    global $scriptStartTime, $eventsTracker;

    $elapsedSeconds = microtime(true) - $scriptStartTime;
    foreach ($eventsTracker as $eventSeconds) {
        $elapsedSeconds -= $eventSeconds;
    }
    return $elapsedSeconds;
}

function validate($appVersion, $appSignature): array 
{
    $errors = [];

    $matched = preg_match(HASH_VALIDATION, $appSignature);
    if (!$matched) {
        $errors[] = "'APP_SIGNATURE' is not a valid signature";
    }

    $matched = preg_match(SEMVER_REGEX, $appVersion);
    if (!$matched) {
        $errors[] = "'APP_VER' is not a valid SemVer";
    }

    if (!in_array(HASH_ALGORYTHM, hash_algos(), true)) {
        $errors[] = "Hash algorythm '" . HASH_ALGORYTHM . "' not available on this server, try with another one";
    }

    if (!empty($errors)) {
        return $errors;
    }

    $fileName = APP_NAME . "-" . $appVersion . ".zip";
    $filePath = PACKAGE_DIR . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($filePath)) {
        return ["application file '$fileName' not found"];
    }

    // calculate hash in chunks (good for large files)
    $ctx = hash_init(HASH_ALGORYTHM);
    $file = fopen($filePath, 'r');
    while(!feof($file)){
        $buffer = fgets($file, HASH_CHUNK_SIZE);
        hash_update($ctx, $buffer);
    }
    $computedHash = hash_final($ctx, false);
    if ($computedHash !== $appSignature) {
        $errors[] = "Computed hash doesn't match APP_SIGNATURE";
        $errors[] = "APP_SIGNATURE: $appSignature";
        $errors[] = "Computed hash: $computedHash";
        $errors[] = "HASH_ALGORYTHM: " . HASH_ALGORYTHM;
        return $errors;
    }

    return [];
}

function isDirEmpty(string $dir): bool
{
  $handle = opendir($dir);
  while (false !== ($entry = readdir($handle))) {
    if ($entry != "." && $entry != "..") {
      closedir($handle);
      return false;
    }
  }
  closedir($handle);
  return true;
}

function isDirWriteable(string $dir): bool
{
    // check if is writeable creating and entually removing a test file
    $uniq = uniqid("writable-test-" . date("YmdHis"), true);
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $uniq, "test content") !== false) {
        @unlink($dir . DIRECTORY_SEPARATOR . $uniq);
        return true;
    }
    return false;
}

function removeDir(string $dir): void 
{
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($dir);
}

// ************ application start ****************

if (empty(RUNNING_WEBSITE_ROOT_DIR)) {
    exitWithError(["the RUNNING_WEBSITE_ROOT_DIR setting is empty"]);
} elseif (!is_dir(RUNNING_WEBSITE_ROOT_DIR)) {
    exitWithError(["cannot find the '" . RUNNING_WEBSITE_ROOT_DIR . "' directory"]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['PASSWORD'])) {
    sleep(1);
    exitWithError(["invalid request"]);
}

// check if the password is valid
if (PASSWORD !== $_POST['PASSWORD']) {
    sleep(1);
    exitWithError(["invalid request"]);
}

if (empty($_POST['COMMAND']) || !in_array(strtolower($_POST['COMMAND'] ?? ''), array_keys(COMMANDS), true)) {
    $errors = ["invalid COMMAND"];
    $errors[] ="valid commands are: ";
    foreach (COMMANDS as $key => $value) {
        $errors[] = "$key:  $value";
    }
    exitWithError($errors);
}
$command = strtolower($_POST['COMMAND']);

if (strtolower($_POST['COMMAND'] ?? '') === 'info' ) {
    $info = [];
    $info[] = "BareDeployer version: " . SAMDEPLOYER_VERSION;
    $info[] = "PHP Version: " . PHP_VERSION;
    if (function_exists("posix_geteuid") && function_exists("posix_getpwuid")) {
        $euid = posix_getpwuid(posix_geteuid());
        $info[] = "UID:GID=" . $euid["uid"] . ":" . $euid["gid"];
    }
    $info[] = "max_execution_time: " . ini_get('max_execution_time') . " seconds";
    $info[] = "memory_limit: " . ini_get('memory_limit');
    $info[] = "disable_functions: " . ini_get('disable_functions');
    $info[] = "open_basedir: " . ini_get('open_basedir');
    exitWithSuccess($info);
}

if ($command === 'extract' || $command === 'extract-and-deploy') {
    // validate parameters and file integrity
    if (empty($_POST["APP_VER"]) || empty($_POST["APP_SIGNATURE"])) {
        exitWithError(["invalid parameters"]);
    }
    if (!version_compare(PHP_VERSION, '8.1.0', '>=') && HASH_ALGORYTHM === 'xxh128') {
        exitWithError(["the hashing algorythm 'xxh128' is available from PHP version 8.1 but this server runs an older PHP version"]);
    }
    try {
        $errors = validate($_POST["APP_VER"], $_POST["APP_SIGNATURE"]);
        if (!empty($errors)) {
            exitWithError($errors);
        }
    } catch (\Throwable $e) {
        exitWithError([$e->getMessage()], $e->getCode());
    }
    $eventsTracker["validate"] = elapsedTime();

    // extract the zip archive
    try {
        $appVersion = $_POST["APP_VER"];
        $fileName = APP_NAME . "-" . $appVersion . ".zip";
        $dirExtract = EXTRACT_TO_DIR . DIRECTORY_SEPARATOR . APP_NAME . "-" . $appVersion;

        $zip = new ZipArchive;
        $res = $zip->open(PACKAGE_DIR . DIRECTORY_SEPARATOR . $fileName, ZipArchive::RDONLY);
        if ($res !== true) {
            exitWithError(["unable to extract the app package"]);
        }
        $res = $zip->extractTo($dirExtract);
        $zip->close();
        if ($res !== true) {
            exitWithError(["unable to extract the app package"]);
        }
    } catch (\Throwable $e) {
        exitWithError([$e->getMessage()], $e->getCode());
    }
    $eventsTracker["extract"] = elapsedTime();

    if ($command !== 'extract-and-deploy') {
        $elapsedSeconds = microtime(true) - $scriptStartTime;
        exitWithSuccess([
            "Success",
            "Validate elapsed time: " . $eventsTracker["validate"] . " seconds",
            "Extract archive time: " . $eventsTracker["extract"] . " seconds",
            "Total elapsed time: $elapsedSeconds seconds"
        ]);
    }
}

// validate deploy
if (empty($_POST["APP_VER"])) {
    exitWithError(["invalid parameters"]);
}
$matched = preg_match(SEMVER_REGEX, $_POST["APP_VER"]);
if (!$matched) {
    exitWithError(["'APP_VER' is not a valid SemVer"]);
}

$appVersion = $_POST["APP_VER"];
$dirExtract = EXTRACT_TO_DIR . DIRECTORY_SEPARATOR . APP_NAME . "-" . $appVersion;
if (is_dir($dirExtract) === false || isDirEmpty($dirExtract)) {
    exitWithError(["unable to find the directory '$dirExtract'"]);
}

$message = [];


// **********************************************************************
// ******************** HERE GOES YOUR FINAL TOUCH **********************
// **********************************************************************


// maybe you want to check some file attributes for example if the parent directory of the directory of your website is writeable
$parentDirectory = dirname(RUNNING_WEBSITE_ROOT_DIR);
if (!is_writable($parentDirectory)) {
    exitWithError(["Cannot substitute the newly extracted app with the running website directory because the parent directory '$parentDirectory' is not writeable"]);
}

// ..maybe you need to move or edit some other files... 

// EXAMPLE of final steps:
// 
// 1. rename RUNNING_WEBSITE_ROOT_DIR adding a postfix with a timestamp like "-backup-20250808162252" 
// 2. rename the new $dirExtract into RUNNING_WEBSITE_ROOT_DIR
// 3. delete the temporary backup

$backupWebsiteDir = RUNNING_WEBSITE_ROOT_DIR . "-backup-" . date("YmdHis");

// 1
$res = rename(RUNNING_WEBSITE_ROOT_DIR, $backupWebsiteDir);
if ($res === false) {
    // THIS IS BAD!
    exitWithError(["failed to rename old website dir into the backup dir: '$backupWebsiteDir', please verify that everything still ok"]);
}

// 2
$res = rename($dirExtract, RUNNING_WEBSITE_ROOT_DIR);
if ($res === false) {
    // THIS IS WORSE!
    if (!is_dir($dirExtract)) {
        // trying to revert to the previously working website
        $res = rename($backupWebsiteDir, RUNNING_WEBSITE_ROOT_DIR);
        if ($res === false) {
            exitWithError(["PANIC ERROR! rename failed and it was not possible to restore the old website!!"]);
        }
    }
    exitWithError(["rename failed but it was possible to restore the old website, please verify that everything still ok"]);
}

// 3
removeDir($backupWebsiteDir);


// **********************************************************************
// ************************* END OF THIS SCRIPT *************************
// **********************************************************************

$eventsTracker["deploy"] = elapsedTime();

$messages[] = "Success";
$elapsedSeconds = microtime(true) - $scriptStartTime;
if ($command === 'extract-and-deploy') {
    $messages[] = "Validate elapsed time: " . $eventsTracker["validate"] . " seconds";
    $messages[] = "Extract archive time: " . $eventsTracker["extract"] . " seconds";
}
$messages[] = "Deploy time: " . $eventsTracker["deploy"] . " seconds";
$messages[] = "Total elapsed time: $elapsedSeconds seconds";
exitWithSuccess($messages);
