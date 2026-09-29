<?php
namespace Reactor\Contracts;

/**
 * Contract for language/translation management.
 */
interface LanguageInterface
{
    /**
     * Translate a key into the given language.
     *
     * @param string      $key    Translation key.
     * @param string|null $lang   Language code (null uses default).
     * @param array       $params Parameters for placeholders.
     * @return string
     */
    public function get(string $key, ?string $lang = null, array $params = []): string;

    /**
     * Get all available language codes.
     *
     * @return array
     */
    public function getAvailableLanguages(): array;

    /**
     * Get the human‑readable name of a language.
     *
     * @param string $code Language code.
     * @return string
     */
    public function getLanguageName(string $code): string;

    /**
     * Reload all translation files.
     */
    public function reload(): void;

    /**
     * Add a translation entry dynamically.
     *
     * @param string $lang  Language code.
     * @param string $key   Translation key.
     * @param string $value Translated string.
     */
    public function addTranslation(string $lang, string $key, string $value): void;

    /**
     * Persist a language file to disk.
     *
     * @param string $lang Language code.
     * @return bool
     */
    public function saveLanguageFile(string $lang): bool;

    /**
     * Get the default language code.
     *
     * @return string
     */
    public function getDefaultLanguage(): string;

    /**
     * Set the default language code.
     *
     * @param string $lang Language code.
     */
    public function setDefaultLanguage(string $lang): void;
}
