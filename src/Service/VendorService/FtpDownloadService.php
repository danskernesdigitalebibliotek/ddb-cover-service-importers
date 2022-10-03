<?php

namespace App\Service\VendorService;

use App\Exception\DownloadFailedException;

/**
 * Class FtpDownloadService.
 */
class FtpDownloadService
{
    /**
     * Download file from remote ftp server.
     *
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $root
     * @param string $localArchive
     * @param string $remoteArchive
     *
     * @throws DownloadFailedException
     */
    public function download(string $host, string $username, string $password, string $root, string $localArchive, string $remoteArchive): void
    {
        $this->makeDir($localArchive);

        $fh = fopen($localArchive, 'w');
        $ftp = ftp_connect($host);
        if (false !== $ftp) {
            if (!ftp_login($ftp, $username, $password)) {
                throw new DownloadFailedException('FTP login failed');
            }
        }

        if (false !== $ftp || false !== $fh) {
            if (!ftp_chdir($ftp, $root)) {
                throw new DownloadFailedException('FTP change dir failed: '.$root);
            }
            if (!ftp_pasv($ftp, true)) {
                throw new DownloadFailedException('FTP change to passive mode failed');
            }
            if (!ftp_fget($ftp, $fh, $remoteArchive)) {
                throw new DownloadFailedException('FTP download failed: '.$remoteArchive);
            }
        }
    }

    private function makeDir(string $localArchive): void
    {
        $path = dirname($localArchive);
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}
