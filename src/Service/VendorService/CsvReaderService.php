<?php

namespace App\Service\VendorService;

/**
 * Class CsvReaderService.
 */
class CsvReaderService
{
    /**
     * Read CSV file one line a the time.
     *
     * @param $filename
     *   The file to read
     *
     * @return \Iterator
     *   Will yield one line at a time
     */
    public function read(string $filename, string $separator = ','): \Iterator
    {
        $file = fopen($filename, 'r');
        while (!feof($file)) {
            $row = fgetcsv($file, 4096, $separator);
            if (!is_array($row)) {
                // Handle empty lines in the input file.
                continue;
            }

            foreach ($row as &$item) {
                $item = iconv('ISO-8859-1', 'UTF-8', $item);
            }

            yield $row;
        }
    }
}
