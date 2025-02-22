<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once '../lib/resize.php';
require_once '../lib/applyFrame.php';
require_once '../lib/applyText.php';
require_once '../lib/log.php';
require_once '../lib/printdb.php';

function applyQR($sourceResource, $qrPath, $x, $y) {
    $qr = imagecreatefrompng($qrPath);
    imagecopy($sourceResource, $qr, $x, $y, 0, 0, imagesx($qr), imagesy($qr));
    return $sourceResource;
}

if (empty($_GET['filename'])) {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': No file provided!';
    logErrorAndDie($errormsg);
}

if (isPrintLocked()) {
    $errormsg = $config['print']['limit_msg'];
    logErrorAndDie($errormsg);
}

$random = Image::create_new_filename('random');
$filename = $_GET['filename'];
$uniquename = substr($filename, 0, -4) . '-' . $random;
$filename_source = $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR . $filename;
$filename_print = $config['foldersAbs']['print'] . DIRECTORY_SEPARATOR . $uniquename;
$filename_codes = $config['foldersAbs']['qrcodes'] . DIRECTORY_SEPARATOR . $filename;
$quality = 100;
$status = false;

// exit with error if file does not exist
if (!file_exists($filename_source)) {
    $errormsg = "File $filename not found";
    logErrorAndDie($errormsg);
}

// Only jpg/jpeg are supported
$imginfo = getimagesize($filename_source);
$mimetype = $imginfo['mime'];
if ($mimetype != 'image/jpg' && $mimetype != 'image/jpeg') {
    $errormsg = basename($_SERVER['PHP_SELF']) . ': The source file type ' . $mimetype . ' is not supported';
    logErrorAndDie($errormsg);
}

// text on print variables
$fontpath = $config['textonprint']['font'];
$fontcolor = $config['textonprint']['font_color'];
$fontsize = $config['textonprint']['font_size'];
$fontlocx = $config['textonprint']['locationx'];
$fontlocy = $config['textonprint']['locationy'];
$linespacing = $config['textonprint']['linespace'];
$fontrot = $config['textonprint']['rotation'];
$line1text = $config['textonprint']['line1'];
$line2text = $config['textonprint']['line2'];
$line3text = $config['textonprint']['line3'];

if (!file_exists($filename_print)) {
    // rotate image if needed
    list($width, $height) = getimagesize($filename_source);
    if ($width > $height || $config['print']['no_rotate'] === true) {
        $image = imagecreatefromjpeg($filename_source);
        imagejpeg($image, $filename_print, $quality);
        imagedestroy($image);
        $rotateQr = false;
    } else {
        $image = imagecreatefromjpeg($filename_source);
        $resultRotated = imagerotate($image, 90, 0);
        imagejpeg($resultRotated, $filename_print, $quality);
        imagedestroy($image);
        $rotateQr = true;
        // re-define width & height after rotation
        list($width, $height) = getimagesize($filename_print);
    }

    $source = imagecreatefromjpeg($filename_print);

    if ($config['print']['qrcode'] && file_exists('../vendor/phpqrcode/lib/full/qrlib.php')) {
        // create qr code
        if (!file_exists($filename_codes)) {
            if ($config['qr']['append_filename']) {
                $url = $config['qr']['url'] . $filename;
            } else {
                $url = $config['qr']['url'];
            }
            include '../vendor/phpqrcode/lib/full/qrlib.php';
            switch ($config['qr']['ecLevel']) {
                case 'QR_ECLEVEL_L':
                    $ecLevel = QR_ECLEVEL_L;
                    break;
                case 'QR_ECLEVEL_M':
                    $ecLevel = QR_ECLEVEL_M;
                    break;
                case 'QR_ECLEVEL_Q':
                    $ecLevel = QR_ECLEVEL_Q;
                    break;
                case 'QR_ECLEVEL_H':
                    $ecLevel = QR_ECLEVEL_H;
                    break;
                default:
                    $ecLevel = QR_ECLEVEL_M;
                    break;
            }
            QRcode::png($url, $filename_codes, $ecLevel, $config['print']['qrSize'], $config['print']['qrMargin']);
            if ($rotateQr) {
                $image = imagecreatefrompng($filename_codes);
                $resultRotated = imagerotate($image, 90, 0);
                imagepng($resultRotated, $filename_codes, 0);
                imagedestroy($image);
            }
            if ($config['print']['qrBgColor'] != '#ffffff') {
                $tocolor = imagecreatefrompng($filename_codes);
                $qrwidth = imagesx($tocolor);
                $qrheight = imagesy($tocolor);
                list($r, $g, $b) = sscanf($config['print']['qrBgColor'], '#%02x%02x%02x');
                $selected = imagecolorallocate($tocolor, $r, $g, $b);

                for ($xpos = 0; $xpos < $qrwidth; $xpos++) {
                    for ($ypos = 0; $ypos < $qrheight; $ypos++) {
                        $currentcolor = imagecolorat($tocolor, $xpos, $ypos);
                        $parts = imagecolorsforindex($tocolor, $currentcolor);

                        if ($parts['red'] == 255 && $parts['green'] == 255 && $parts['blue'] == 255) {
                            imagesetpixel($tocolor, $xpos, $ypos, $selected);
                        }
                    }
                }
                imagepng($tocolor, $filename_codes, 0);
            }
        }

        if ($config['print']['print_frame'] && testFile($config['print']['frame'])) {
            $source = applyFrame($source, $config['print']['frame'], true);
        }

        list($qrWidth, $qrHeight) = getimagesize($filename_codes);

        if (is_numeric($config['print']['qrOffset'])) {
            $offset = $config['print']['qrOffset'];
        } else {
            $offset = 10;
        }

        switch ($config['print']['qrPosition']) {
            case 'topLeft':
                $x = $offset;
                $y = $offset;
                break;
            case 'top':
                $x = ($width - $qrWidth) / 2;
                $y = $offset;
                break;
            case 'topRight':
                $x = $width - ($qrWidth + $offset);
                $y = $offset;
                break;
            case 'right':
                $x = $width - $qrWidth - $offset;
                $y = ($height - $qrHeight) / 2;
                break;
            case 'bottomRight':
                $x = $width - ($qrWidth + $offset);
                $y = $height - ($qrHeight + $offset);
                break;
            case 'bottom':
                $x = ($width - $qrWidth) / 2;
                $y = $height - $qrHeight - $offset;
                break;
            case 'bottomLeft':
                $x = $offset;
                $y = $height - ($qrHeight + $offset);
                break;
            case 'left':
                $x = $offset;
                $y = ($height - $qrHeight) / 2;
                break;
            default:
                $x = $width - ($qrWidth + $offset);
                $y = $height - ($qrHeight + $offset);
                break;
        }
        $source = applyQR($source, $filename_codes, $x, $y);
    } else {
        if ($config['print']['print_frame'] && testFile($config['print']['frame'])) {
            $source = applyFrame($source, $config['print']['frame'], true);
        }
    }

    if ($config['textonprint']['enabled'] && testFile($config['textonprint']['font'])) {
        $source = applyText($source, $fontsize, $fontrot, $fontlocx, $fontlocy, $fontcolor, $fontpath, $line1text, $line2text, $line3text, $linespacing);
    }

    if ($config['print']['crop']) {
        $crop_width = $config['print']['crop_width'];
        $crop_height = $config['print']['crop_height'];
        $source = resizeCropImage($crop_width, $crop_height, $source);
    }

    imagejpeg($source, $filename_print, $quality);
    imagedestroy($source);
}

// print image
$status = 'ok';
$cmd = sprintf($config['print']['cmd'], $filename_print);
$cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

exec($cmd, $output, $returnValue);

addToPrintDB($filename, $uniquename);

$linecount = 0;
if ($config['print']['limit'] > 0) {
    $linecount = getPrintCountFromDB();
    if ($linecount % $config['print']['limit'] == 0) {
        if (lockPrint()) {
            $status = 'locking';
        } else {
            if ($config['dev']['loglevel'] > 1) {
                $errormsg = basename($_SERVER['PHP_SELF']) . ': Error creating the file ' . PRINT_LOCKFILE;
                logError($errormsg);
            }
        }
    }
    file_put_contents(PRINT_COUNTER, $linecount);
}

$LogData = [
    'status' => $status,
    'count' => $linecount,
    'msg' => $cmd,
    'returnValue' => $returnValue,
    'output' => $output,
    'php' => basename($_SERVER['PHP_SELF']),
];
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}

die($LogString);
