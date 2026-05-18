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
		 * IDE stub for Unraid's publish().
		 *
		 * Runtime implementation is provided by dynamix publish.php; pushes a
		 * message onto an nchan channel.
		 *
		 * @param  string  $message  Message body.
		 * @param  string  $channel  nchan channel name.
		 * @return void
		 */
		function publish($message = '', $channel = '')
		{
		}
}

if (!function_exists('publish_noDupe')) {
		/**
		 * IDE stub for Unraid's publish_noDupe().
		 *
		 * Like publish(), but suppresses consecutive duplicate messages on the same channel.
		 *
		 * @param  string  $message
		 * @param  string  $channel
		 * @return void
		 */
		function publish_noDupe($message = '', $channel = '')
		{
		}
}

if (!function_exists('markdown')) {
		/**
		 * IDE stub for Unraid's markdown() (lowercase).
		 *
		 * Runtime implementation lives in webGui/include/Markdown.php.
		 *
		 * @param  string  $text  Markdown source.
		 * @return string Rendered HTML.
		 */
		function markdown($text = '')
		{
				return (string)$text;
		}
}

if (!function_exists('Markdown')) {
		/**
		 * IDE stub for Unraid's Markdown() (capitalized).
		 *
		 * @param  string  $text  Markdown source.
		 * @return string Rendered HTML.
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
		/**
		 * IDE stub for Unraid's DockerClient.
		 *
		 * Real implementation lives in plugins/dynamix.docker.manager/include/DockerClient.php.
		 */
		class DockerClient
		{
				/**
				 * IDE stub for DockerClient::stopContainer().
				 *
				 * @param  string  $id  Container ID.
				 * @return mixed
				 */
				public function stopContainer($id)
				{
						return null;
				}

				/**
				 * IDE stub for DockerClient::getAllInfo().
				 *
				 * @return array<string,mixed>
				 */
				public function getAllInfo()
				{
						return [];
				}

				/**
				 * IDE stub for DockerClient::getDockerContainers().
				 *
				 * @return array<int,mixed>
				 */
				public function getDockerContainers()
				{
						return [];
				}

				/**
				 * IDE stub for DockerClient::removeContainer().
				 *
				 * @param  string  $id  Container ID.
				 * @return mixed
				 */
				public function removeContainer($id)
				{
						return null;
				}

				/**
				 * IDE stub for DockerClient::removeImage().
				 *
				 * @param  string  $id  Image ID.
				 * @return mixed
				 */
				public function removeImage($id)
				{
						return null;
				}
		}
}

if (!class_exists('DockerTemplates')) {
		/**
		 * IDE stub for Unraid's DockerTemplates.
		 *
		 * Real implementation lives in plugins/dynamix.docker.manager/include/DockerClient.php.
		 */
		class DockerTemplates
		{
				/**
				 * IDE stub for DockerTemplates::getAllInfo().
				 *
				 * @param  bool  $readDefault
				 * @param  bool  $fullPath
				 * @param  bool  $includeContainers
				 * @return array<string,mixed>
				 */
				public function getAllInfo($readDefault = false, $fullPath = false, $includeContainers = false)
				{
						return [];
				}
		}
}
