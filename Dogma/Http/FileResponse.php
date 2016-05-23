<?php
/**
 * This file is part of the Dogma library (https://github.com/paranoiq/dogma)
 *
 * Copyright (c) 2012 Vlasta Neubauer (@paranoiq)
 *
 * For the full copyright and license information read the file 'license.md', distributed with this source code
 */

namespace Dogma\Http;

use Nette\Utils\Strings;


class FileResponse extends Response
{

    /** @var string */
    private $fileName;

    /**
     * @param string
     * @param mixed[]
     * @param int
     */
    public function __construct(string $fileName, array $info, int $error)
    {
        parent::__construct(null, $info, $error);

        $this->fileName = $fileName;
    }

    /**
     * @return string[]
     */
    public function getHeaders(): array
    {
        if (!$this->headers) {
            $this->parseFile();
        }

        return $this->headers;
    }

    public function getBody(): string
    {
        if (!$this->headers) {
            $this->parseFile();
        }

        return file_get_contents($this->fileName);
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Remove headers from downloaded file
     */
    private function parseFile()
    {
        if (($fp = @fopen($this->fileName . '.tmp', 'rb')) === false) {
            throw new ResponseException(sprintf('Fopen error for file \'%s.tmp\'.', $this->fileName));
        }

        $headers = Strings::split(@fread($fp, $this->info['header_size']), "~[\n\r]+~", PREG_SPLIT_NO_EMPTY);
        $this->headers = static::parseHeaders($headers);

        @fseek($fp, $this->info['header_size']);

        if (($ft = @fopen($this->fileName, 'wb')) === false) {
            throw new ResponseException(sprintf('Write error for file \'%s\'.', $this->fileName));
        }

        while (!feof($fp)) {
            $row = fgets($fp, 4096);
            fwrite($ft, $row);
        }

        @fclose($fp);
        @fclose($ft);

        if (!@unlink($this->fileName . '.tmp')) {
            throw new ResponseException(sprintf('Error while deleting file \'%s\'.', $this->fileName));
        }

        chmod($this->fileName, 0755);

        if (!$this->headers) {
            throw new RequestException('Headers parsing failed');
        }
    }

}
