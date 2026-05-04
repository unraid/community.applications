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
		 * Invoke a plugin helper method identified by $method for an optional plugin file and return its result as a string.
		 *
		 * @param string $method The name of the plugin helper method to invoke.
		 * @param string $plugin_file Optional plugin file path or identifier to target; empty selects the default context.
		 * @return string The string result produced by the plugin helper.
		 */
		function plugin($method, $plugin_file = '')
		{
				return '';
		}
}

if (!function_exists('parse_plugin_cfg')) {
		/**
		 * Parse a plugin configuration and return its settings as an associative array.
		 *
		 * @param string $plugin Path to the plugin configuration file or plugin identifier.
		 * @return array<string,mixed> Associative map of configuration keys to their values.
		 */
		function parse_plugin_cfg($plugin)
		{
				return [];
		}
}

if (!function_exists('parse_lang_file')) {
		/**
		 * Parse a language (translation) file and produce an associative array of its entries.
		 *
		 * @param string $file Path to the language file to parse.
		 * @param bool $keepComments Whether to include comment lines/blocks in the returned array.
		 * @return array<string,mixed> Associative array of translation keys to values; when `$keepComments` is true the array may include comment entries or metadata. 
		 */
		function parse_lang_file($file = '', $keepComments = false)
		{
				return [];
		}
}

if (!function_exists('publish')) {
		/**
		 * Publishes a message to the specified channel.
		 *
		 * @param string $message The message to publish.
		 * @param string $channel The channel to publish the message to.
		 * @return void
		 */
		function publish($message = '', $channel = '')
		{
		}
}

if (!function_exists('publish_noDupe')) {
		/**
		 * Publishes a message to a channel only if an identical message has not already been published.
		 *
		 * @param string $message The message to publish.
		 * @param string $channel The channel to publish the message to.
		 */
		function publish_noDupe($message = '', $channel = '')
		{
		}
}

if (!function_exists('markdown')) {
		/**
		 * Return the provided text cast to a string.
		 *
		 * @param mixed $text The value to cast to string.
		 * @return string The input converted to a string.
		 */
		function markdown($text = '')
		{
				return (string)$text;
		}
}

if (!function_exists('Markdown')) {
		/**
		 * Return the provided value converted to a string.
		 *
		 * @param mixed $text Value to convert to string.
		 * @return string The value cast to a string.
		 */
		function Markdown($text = '')
		{
				return (string)$text;
		}
}

if (!function_exists('autov')) {
		/**
		 * Cast the provided path value to a string.
		 *
		 * @param string|mixed $path The path or value to convert.
		 * @return string The input value cast to a string.
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
				 * Stop the Docker container identified by the given ID.
				 *
				 * @param string $id The container ID or name.
				 * @return mixed The result of the stop operation (implementation-defined).
				 */
				public function stopContainer($id)
				{
						return null;
				}

				/**
				 * Retrieve all available Docker client information.
				 *
				 * @return array<string,mixed> Associative array of Docker client information keyed by string.
				 */
				public function getAllInfo()
				{
						return [];
				}

				/**
				 * Retrieve information about Docker containers.
				 *
				 * @return array<int,mixed> Array of container information entries indexed by integer; empty if no containers are available.
				 */
				public function getDockerContainers()
				{
						return [];
				}

				/**
				 * Remove a Docker container identified by the given ID or name.
				 *
				 * @param string $id The container identifier (ID or name).
				 * @return mixed The result of the removal operation; implementation-defined.
				 */
				public function removeContainer($id)
				{
						return null;
				}

				/**
				 * Placeholder for removing a Docker image by identifier.
				 *
				 * @param string $id The Docker image identifier (ID or name).
				 * @return mixed Always `null` in this IDE/stub implementation.
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
				 * Retrieve all Docker template information.
				 *
				 * @param bool $readDefault If true, include default templates in the result.
				 * @param bool $fullPath If true, return full file paths for template entries.
				 * @param bool $includeContainers If true, include associated container information.
				 * @return array<string,mixed> An array of template information (empty if no templates are found).
				 */
				public function getAllInfo($readDefault = false, $fullPath = false, $includeContainers = false)
				{
						return [];
				}
		}
}
