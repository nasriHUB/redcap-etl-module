<?php

#-------------------------------------------------------
# Copyright (C) 2019 The Trustees of Indiana University
# SPDX-License-Identifier: BSD-3-Clause
#-------------------------------------------------------

namespace IU\RedCapEtlModule;

/**
 * Class for filtering/escaping user input.
 */
class Filter
{
    public static $allowedHelpTags = [
        'a', 'b', 'blockquote', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'i', 'li', 'ol', 'p', 'pre', 'strong',
        'table', 'tbody', 'td', 'th', 'thead', 'tr',
        'ul'
    ];

    /**
     * Escape text for displaying as HTML.
     * This method only works within REDCap context.
     *
     * @param string $value the text to display.
     */
    public static function escapeForHtml($value)
    {
        return \REDCap::escapeHtml($value);
    }

    public static function escapeForHtmlAttribute($value)
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    /**
     * Escape value for use as URL parameters.
     */
    public static function escapeForUrlParameter($value)
    {
        return urlencode($value);
    }

    public static function escapeForJavaScriptInDoubleQuotes($value)
    {
        # REDCap's JavaScript escape function for double quotes
        return js_escape2($value);
    }

    public static function escapeForMysql($value)
    {
        return db_escape($value);
    }

    public static function stripTags($value)
    {
        return trim(strip_tags($value));
    }

    public static function stripTagsArray($values)
    {
        $newValues = $values;
        foreach ($values as $key => $value) {
            $newValues[$key] = strip_tags($value);
        }
        return $newValues;
    }

    public static function stripTagsArrayRecursive($values)
    {
        $newValues = $values;
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $newValues[$key] = self::stripTagsArrayRecursive($values[$key]);
            } else {
                $newValues[$key] = trim(strip_tags($value));
            }
        }
        return $newValues;
    }

    public static function isEmail($value)
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    public static function isUrl($value)
    {
        return filter_var($value, FILTER_VALIDATE_URL);
    }

    public static function sanitizeInt($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Removes tags and invalid characters for labels
     * (internal string values used for submit buttons, etc.).
     */
    public static function sanitizeLabel($value)
    {
        $flags = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK;
        return filter_var($value, FILTER_SANITIZE_STRING, $flags);
    }

    /**
     * Removes invalid characters for internal button labels.
     */
    public static function sanitizeButtonLabel($value)
    {
        $value = preg_replace('/([^a-zA-Z0-9_\- .])/', '', $value);
        return $value;
    }

    /**
     * Removes invalid characters from dates
     */
    public static function sanitizeDate($value)
    {
        $value = preg_replace('/([^0-9\-\/])/', '', $value);
        return $value;
    }

    /**
     * Removes tags and invalid characters for strings.
     */
    public static function sanitizeString($value)
    {
        $flags = FILTER_FLAG_STRIP_LOW;
        return filter_var($value, FILTER_SANITIZE_STRING, $flags);
    }

    /**
     * Sanitizes custom help messages.
     * Removes all tags except allowed tags, and removes all tag
     * attributes except for the "href" attribute that have the
     * form href="http..." for the "a" tag.
     */
    public static function sanitizeHelp($text)
    {
        # Remove leading spacing from tags
        $text = preg_replace('/<\s+/', '<', $text);

        # Remove trailing space from tags
        $text = preg_replace('/\s+>/', '>', $text);

        # Close nested tags, for example, change "<a <a>" => "<a> <a>"
        $text = preg_replace('/<\s*([a-z][a-z0-9]*)([^<>]*<)/i', '<${1}>${2}', $text);

        # Terminate non-terminated a tags, e.g.: "<a this is a test" => "<a> this is a test"
        $text = preg_replace('/<\s*[a-z][a-z0-9]*\s+([^>]*$)/i', '<a>$1', $text);

        # Remove non-allowed tags
        $allowedTagsString = '<' . implode('><', self::$allowedHelpTags) . '>';
        $text = strip_tags($text, $allowedTagsString);

        # Remove all attributes of allowed tags, except for the "a" tag
        foreach (self::$allowedHelpTags as $tag) {
            if ($tag !== 'a') {
                $text = preg_replace("/<{$tag}\s+[^>]*?(\/?)>/i", '<' . $tag . '$1>', $text);
            }
        }

        # Remove "a" tag attributes that are not href with the form href="http..."
        $tempTag = 'a__TEMP_FILTER__';
        $text = preg_replace('/<' . $tempTag . '\s+/', '<a ', $text);  # Make sure there are no temp tags
        $text = preg_replace('/<a\s+[^>]*(href="http[^"]*")[^>]*>/i', '<' . $tempTag . ' $1>', $text);
        $text = preg_replace('/<a\s+[^>]*>/i', '<a>', $text);
        $text = preg_replace('/<' . $tempTag . '\s+/', '<a ', $text);
        return $text;
    }

    public static function sanitizeRulesStatus($text)
    {
        $text = strip_tags($text, '<br>');
        return $text;
    }

    public static function getAllowedHelpTagsString()
    {
        $isFirstTag = true;
        $tagString = '';
        foreach (self::$allowedHelpTags as $tag) {
            if ($isFirstTag) {
                $isFirstTag = false;
            } else {
                $tagString .= ', ';
            }
            $tagString .= '<' . $tag . '>';
        }
        return $tagString;
    }
}
