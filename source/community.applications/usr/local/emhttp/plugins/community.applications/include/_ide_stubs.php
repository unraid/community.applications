<?php
/**
 * IDE-only symbol stubs for static analysis.
 *
 * This file is intentionally not included by runtime code.
 */

if (!defined('CA_PATHS')) {
    define('CA_PATHS', []);
}

if (!function_exists('plugin')) {
    /**
     * Runtime implementation is provided by Unraid Wrappers.php.
     *
     * @param string $method
     * @param string $plugin_file
     * @return string
     */
    function plugin($method, $plugin_file = '')
    {
        return '';
    }
}

if (!function_exists('parse_plugin_cfg')) {
    /**
     * Runtime implementation is provided by Unraid Wrappers.php.
     *
     * @param string $plugin
     * @return array<string,mixed>
     */
    function parse_plugin_cfg($plugin)
    {
        return [];
    }
}

if (!function_exists('parse_lang_file')) {
    /**
     * Runtime implementation is provided by Unraid Wrappers.php.
     *
     * @param string $file
     * @param bool $keepComments
     * @return array<string,mixed>
     */
    function parse_lang_file($file = '', $keepComments = false)
    {
        return [];
    }
}

if (!function_exists('publish')) {
    /**
     * @return void
     */
    function publish($message = '', $channel = '')
    {
    }
}

if (!function_exists('publish_noDupe')) {
    /**
     * @return void
     */
    function publish_noDupe($message = '', $channel = '')
    {
    }
}

if (!function_exists('markdown')) {
    /**
     * @return string
     */
    function markdown($text = '')
    {
        return (string)$text;
    }
}

if (!function_exists('Markdown')) {
    /**
     * @return string
     */
    function Markdown($text = '')
    {
        return (string)$text;
    }
}

if (!function_exists('autov')) {
    /**
     * Runtime implementation is provided by Unraid Wrappers.php.
     *
     * @param string $path
     * @return string
     */
    function autov($path = '')
    {
        return (string)$path;
    }
}

if (!class_exists('DockerClient')) {
    class DockerClient
    {
        /**
         * @param string $id
         * @return mixed
         */
        public function stopContainer($id)
        {
            return null;
        }

        /**
         * @return array<string,mixed>
         */
        public function getAllInfo()
        {
            return [];
        }

        /**
         * @return array<int,mixed>
         */
        public function getDockerContainers()
        {
            return [];
        }

        /**
         * @param string $id
         * @return mixed
         */
        public function removeContainer($id)
        {
            return null;
        }

        /**
         * @param string $id
         * @return mixed
         */
        public function removeImage($id)
        {
            return null;
        }
    }
}

if (!class_exists('DockerTemplates')) {
    class DockerTemplates
    {
        /**
         * @param bool $readDefault
         * @param bool $fullPath
         * @param bool $includeContainers
         * @return array<string,mixed>
         */
        public function getAllInfo($readDefault = false, $fullPath = false, $includeContainers = false)
        {
            return [];
        }
    }
}
