<?php
/**
 * This file is part of the Dogma library (https://github.com/paranoiq/dogma)
 *
 * Copyright (c) 2012 Vlasta Neubauer (@paranoiq)
 *
 * For the full copyright and license information read the file 'license.md', distributed with this source code
 */

namespace Dogma\Http;


class CurlHelpers {


    // Errors ----------------------------------------------------------------------------------------------------------


    /**
     * @param integer
     * @return string
     */
    public static function getCurlErrorName($error) {
        $consts = get_defined_constants(true);
        foreach ($consts['curl'] as $name => $value) {
            if ($value === $error && substr($name, 0, 6) === 'CURLE_') return $name;
        }

        return 'UNKNOWN_ERROR';
    }


    /**
     * @param integer
     * @return string
     */
    public static function getCurlMultiErrorName($error) {
        $consts = get_defined_constants(true);
        $curl = $consts['curl'];
        foreach ($curl as $name => $value) {
            if ($value === $error && substr($name, 0, 6) === 'CURLM_') return $name;
        }

        return 'UNKNOWN_ERROR';
    }


    // Options ---------------------------------------------------------------------------------------------------------


    /**
     * @param string
     * @return integer
     */
    public static function getCurlOptionNumber($name) {
        $name = strtoupper($name);

        return constant('CURLOPT_' . $name);
    }


    /**
     * @param string
     * @return integer
     */
    public static function getCurlOptionName($option) {
        $consts = get_defined_constants(true);
        foreach ($consts['curl'] as $name => $value) {
            if ($value === $option && substr($name, 0, 8) === 'CURLOPT_') return $name;
        }

        return null;
    }


    // Info ------------------------------------------------------------------------------------------------------------


    /**
     * @param integer
     * @return string|null
     */
    public static function getCurlInfoName($num) {
        static $translate = array(
            CURLINFO_EFFECTIVE_URL => 'url',
            CURLINFO_HTTP_CODE => 'http_code',
            CURLINFO_FILETIME => 'filetime',
            CURLINFO_TOTAL_TIME => 'total_time',
            CURLINFO_NAMELOOKUP_TIME => 'namelookup_time',
            CURLINFO_CONNECT_TIME => 'connect_time',
            CURLINFO_PRETRANSFER_TIME => 'pretransfer_time',
            CURLINFO_STARTTRANSFER_TIME => 'starttransfer_time',
            CURLINFO_REDIRECT_TIME => 'redirect_time',
            CURLINFO_REDIRECT_COUNT => 'redirect_count',
            CURLINFO_SIZE_UPLOAD => 'size_upload',
            CURLINFO_SIZE_DOWNLOAD => 'size_download',
            CURLINFO_SPEED_DOWNLOAD => 'speed_download',
            CURLINFO_SPEED_UPLOAD => 'speed_upload',
            CURLINFO_HEADER_SIZE => 'header_size',
            CURLINFO_HEADER_OUT => 'request_header',
            CURLINFO_REQUEST_SIZE => 'request_size',
            CURLINFO_SSL_VERIFYRESULT => 'ssl_verify_result',
            CURLINFO_CONTENT_LENGTH_DOWNLOAD => 'download_content_length',
            CURLINFO_CONTENT_LENGTH_UPLOAD => 'upload_content_length',
            CURLINFO_CONTENT_TYPE => 'content_type',
        );
        // CURLINFO_PRIVATE
        // certinfo

        $consts = get_defined_constants(true);
        foreach ($consts['curl'] as $name => $value) {
            if ($value === $num && substr($name, 0, 9) === 'CURLINFO_')
                return $translate[$name];
        }

        return null;
    }


}
