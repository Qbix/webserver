<?php

/**
 * Lightweight text/i18n for Qbix Server.
 *
 * Loads JSON translation files from text/<source>/<lang>.json,
 * with automatic fallback to English. Inspired by the Qbix Platform's
 * Q_Text class but standalone — no framework dependencies.
 *
 * Usage:
 *   Q_Text::setLanguageFromHeader('es,en;q=0.8');
 *   $t = Q_Text::get('Q/server');
 *   echo $t['errors']['404']['title']; // "No encontrado"
 *
 * @class Q_Text
 */
class Q_Text
{
    /**
     * Current language code (lowercase two-letter ISO 639-1).
     * @property $language
     * @static
     */
    public static $language = 'en';

    /**
     * Current locale (uppercase two-letter ISO 3166-1).
     * @property $locale
     * @static
     */
    public static $locale = '';

    /**
     * Default fallback language.
     * @property $defaultLanguage
     * @static
     */
    public static $defaultLanguage = 'en';

    /**
     * In-memory cache of loaded text collections.
     * Keyed by "$lang/$source".
     * @property $collection
     * @static
     */
    public static $collection = array();

    /**
     * Base directories to search for text/ folders, in priority order.
     * Set by init() or manually. Defaults to src/Q/ within the server root.
     * @property $paths
     * @static
     */
    public static $paths = array();

    /**
     * Initialize text paths. Called once at startup.
     * @method init
     * @static
     * @param {array} $paths Directories that contain text/ subdirectories
     */
    static function init($paths = array())
    {
        self::$paths = $paths;
    }

    /**
     * Set language from an Accept-Language header value.
     *
     * Parses quality values and picks the best available language.
     * Falls back to 'en' if nothing matches.
     *
     * @method setLanguageFromHeader
     * @static
     * @param {string} $header The Accept-Language header value
     * @return {string} The language code that was set
     */
    static function setLanguageFromHeader($header)
    {
        if (!$header) {
            self::$language = self::$defaultLanguage;
            self::$locale = '';
            return self::$language;
        }

        $langs = self::parseAcceptLanguage($header);
        // $langs is sorted by quality descending
        foreach ($langs as $entry) {
            $lang = $entry['lang'];
            $locale = $entry['locale'];
            // Check if we have translations for this language
            if (self::hasLanguage($lang)) {
                self::$language = $lang;
                self::$locale = $locale;
                return $lang;
            }
        }

        // No match found, use default
        self::$language = self::$defaultLanguage;
        self::$locale = '';
        return self::$language;
    }

    /**
     * Set language explicitly.
     * @method setLanguage
     * @static
     * @param {string} $language Two-letter language code
     * @param {string} $locale Two-letter locale code (optional)
     */
    static function setLanguage($language, $locale = '')
    {
        self::$language = strtolower(preg_replace('/[^a-zA-Z]/', '', $language));
        self::$locale = $locale ? strtoupper(preg_replace('/[^a-zA-Z]/', '', $locale)) : '';
    }

    /**
     * Get translated text from a source file.
     *
     * Loads text/<source>/<lang>.json and merges it on top of
     * the English default so missing keys fall back gracefully.
     *
     * @method get
     * @static
     * @param {string} $source The text source name (e.g. 'Q/server')
     * @param {array} $options Optional overrides: 'language', 'locale'
     * @return {array} The translation content as a nested array
     */
    static function get($source, $options = array())
    {
        $lang = isset($options['language'])
            ? strtolower($options['language'])
            : self::$language;

        $cacheKey = "$lang/$source";
        if (isset(self::$collection[$cacheKey])) {
            return self::$collection[$cacheKey];
        }

        // Load English defaults first
        $content = array();
        if ($lang !== self::$defaultLanguage) {
            $defaultKey = self::$defaultLanguage . "/$source";
            if (isset(self::$collection[$defaultKey])) {
                $content = self::$collection[$defaultKey];
            } else {
                $content = self::loadFile($source, self::$defaultLanguage);
                self::$collection[$defaultKey] = $content;
            }
        }

        // Load requested language and merge on top
        $translated = self::loadFile($source, $lang);
        if ($translated) {
            $content = self::mergeDeep($content, $translated);
        }

        self::$collection[$cacheKey] = $content;
        return $content;
    }

    /**
     * Interpolate {{placeholders}} in a string.
     *
     * @method interpolate
     * @static
     * @param {string} $text Text with {{key}} placeholders
     * @param {array} $vars Key => value replacements
     * @return {string} Interpolated text
     */
    static function interpolate($text, $vars = array())
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Check whether a language file exists for a given language.
     * @method hasLanguage
     * @static
     * @param {string} $lang Two-letter language code
     * @return {boolean}
     */
    static function hasLanguage($lang)
    {
        $lang = strtolower(preg_replace('/[^a-zA-Z]/', '', $lang));
        foreach (self::$paths as $base) {
            // Check for the server text source (primary)
            $file = $base . '/text/Q/server/' . $lang . '.json';
            if (is_file($file)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the list of available language codes.
     * @method availableLanguages
     * @static
     * @return {array} Language codes
     */
    static function availableLanguages()
    {
        $langs = array();
        foreach (self::$paths as $base) {
            $dir = $base . '/text/Q/server';
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*.json') as $file) {
                $langs[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        return array_unique($langs);
    }

    /**
     * Reset all state (for testing).
     * @method reset
     * @static
     */
    static function reset()
    {
        self::$language = 'en';
        self::$locale = '';
        self::$collection = array();
    }

    // ── Internal ──────────────────────────────────────

    /**
     * Parse Accept-Language header into sorted language entries.
     * @param {string} $header
     * @return {array} Sorted by quality descending. Each entry:
     *   ['lang' => 'en', 'locale' => 'US', 'q' => 1.0]
     */
    private static function parseAcceptLanguage($header)
    {
        $result = array();
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $q = 1.0;
            $segments = explode(';', $part);
            $tag = trim($segments[0]);

            // Parse quality value
            for ($i = 1; $i < count($segments); $i++) {
                $param = trim($segments[$i]);
                if (strpos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }

            // Parse language tag: "en-US" → lang=en, locale=US
            $tagParts = explode('-', $tag);
            $lang = strtolower($tagParts[0]);
            $locale = isset($tagParts[1]) ? strtoupper($tagParts[1]) : '';

            if ($lang === '*') continue; // skip wildcard

            $result[] = array('lang' => $lang, 'locale' => $locale, 'q' => $q);
        }

        // Sort by quality descending
        usort($result, function ($a, $b) {
            return $b['q'] <=> $a['q'];
        });

        return $result;
    }

    /**
     * Load a JSON text file from the text paths.
     * @param {string} $source e.g. 'Q/server'
     * @param {string} $lang e.g. 'es'
     * @return {array} Decoded content, or empty array
     */
    private static function loadFile($source, $lang)
    {
        $lang = preg_replace('/[^a-zA-Z_-]/', '', $lang);
        foreach (self::$paths as $base) {
            $file = $base . '/text/' . $source . '/' . $lang . '.json';
            if (is_file($file)) {
                $json = file_get_contents($file);
                $data = json_decode($json, true);
                return is_array($data) ? $data : array();
            }
        }
        return array();
    }

    /**
     * Deep-merge two arrays. Values from $b override $a.
     * @param {array} $a Base array
     * @param {array} $b Override array
     * @return {array} Merged result
     */
    private static function mergeDeep($a, $b)
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = self::mergeDeep($a[$key], $value);
            } else {
                $a[$key] = $value;
            }
        }
        return $a;
    }
}
