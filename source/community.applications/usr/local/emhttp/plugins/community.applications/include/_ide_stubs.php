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
		 * Invoke or query plugin-related functionality identified by a method name.
		 *
		 * @param string $method The plugin method or query to perform.
		 * @param string $plugin_file Optional plugin file path or identifier to target.
		 * @return string The result of the plugin call as a string, or an empty string on failure.
		 */
		function plugin($method, $plugin_file = '')
		{
				return '';
		}
}

if (!function_exists('parse_plugin_cfg')) {
		/**
		 * Return the parsed plugin configuration as an associative array (IDE stub; runtime implementation provided by Unraid Wrappers.php).
		 *
		 * @param string $plugin Path to the plugin file or plugin identifier.
		 * @return array<string,mixed> Parsed plugin configuration keyed by setting names.
		 */
		function parse_plugin_cfg($plugin)
		{
				return [];
		}
}

if (!function_exists('parse_lang_file')) {
		/**
		 * Parse a language file and return its entries as an associative array.
		 *
		 * @param string $file Path to the language file to parse.
		 * @param bool $keepComments If true, include comment entries in the returned array.
		 * @return array<string,mixed> An associative array of language keys to values; comment entries are included only when `$keepComments` is true.
		 */
		function parse_lang_file($file = '', $keepComments = false)
		{
				return [];
		}
}

if (!function_exists('publish')) {
		/**
		 * Publish a message to a specified channel.
		 *
		 * In this IDE-only stub the function is a no-op; runtime behavior is provided by the actual environment.
		 *
		 * @param string $message The message to publish.
		 * @param string $channel The channel to publish to (optional).
		 */
		function publish($message = '', $channel = '')
		{
		}
}

if (!function_exists('publish_noDupe')) {
		/**
		 * Publishes a message to a channel if an identical message has not already been published.
		 *
		 * @param string $message The message to publish.
		 * @param string $channel The target channel.
		 */
		function publish_noDupe($message = '', $channel = '')
		{
		}
}

if (!function_exists('markdown')) {
		/**
		 * Convert Markdown-formatted text to a string (IDE/static-analysis stub).
		 *
		 * @param mixed $text Text containing Markdown.
		 * @return string The formatted output; in this stub the input cast to string.
		 */
		function markdown($text = '')
		{
				return (string)$text;
		}
}

if (!function_exists('Markdown')) {
		/**
		 * Coerces the given value to a string.
		 *
		 * @param mixed $text The value to convert to a string.
		 * @return string The input converted to a string.
		 */
		function Markdown($text = '')
		{
				return (string)$text;
		}
}

if (!function_exists('autov')) {
		/**
		 * Return an autoversioned asset path for the given path.
		 *
		 * In this stub implementation, the input is returned unchanged as a string.
		 *
		 * @param string $path Asset path to autoversion.
		 * @return string The autoversioned path, or the original path in this stub.
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
				 * Stop a Docker container identified by its ID or name.
				 *
				 * @param string $id The container ID or name to stop.
				 * @return mixed Result of the stop operation; value depends on the runtime implementation.
				 */
				public function stopContainer($id)
				{
						return null;
				}

				/**
				 * Retrieve information about all Docker templates.
				 *
				 * @return array<string,mixed> An associative array mapping template identifiers to their metadata.
				 */
				public function getAllInfo()
				{
						return [];
				}

				/**
				 * Retrieve information about Docker containers.
				 *
				 * @return array<int,mixed> An array of container information entries indexed by integer.
				 */
				public function getDockerContainers()
				{
						return [];
				}

				/**
				 * Remove a Docker container identified by its ID or name.
				 *
				 * @param string $id The container ID or name to remove.
				 * @return mixed Implementation-dependent result of the removal operation.
				 */
				public function removeContainer($id)
				{
						return null;
				}

				/**
				 * Remove a Docker image by its identifier.
				 *
				 * @param string $id The image identifier (ID or name).
				 * @return mixed The result of the removal operation, or `null` if no result is available. 
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
				 * Retrieve information about available Docker templates.
				 *
				 * @param bool $readDefault Whether to include default templates.
				 * @param bool $fullPath Whether template file paths should be returned as full filesystem paths.
				 * @param bool $includeContainers Whether to include templates associated with existing containers.
				 * @return array<string,mixed> An associative array of template metadata keyed by template identifier.
				 */
				public function getAllInfo($readDefault = false, $fullPath = false, $includeContainers = false)
				{
						return [];
				}
		}
}
