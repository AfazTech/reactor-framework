<?php

namespace Reactor\Core;

use Amp\File;
use Reactor\Contracts\LanguageInterface;

/**
 * Language/translation manager that loads JSON language files.
 *
 * File access goes through amphp/file so that loading and saving
 * translations never blocks the event loop.
 */
class Language implements LanguageInterface
{
    private array $translations = [];
    private string $defaultLanguage;
    private array $loadedLanguages = [];
    private array $languageNames;
    private string $langDir;

    public function __construct(Paths $paths, Config $config)
    {
        $this->langDir = $paths->lang();
        $this->defaultLanguage = $config->get('app.default_language', 'en');
        $this->languageNames = (array) $config->get('app.language_names', [
            'fa' => 'فارسی',
            'en' => 'English',
        ]);
        $this->loadAllLanguages();
    }

    private function loadAllLanguages(): void
    {
        if (!File\isDirectory($this->langDir)) {
            File\createDirectoryRecursively($this->langDir, 0755);
            return;
        }

        foreach (File\listFiles($this->langDir) as $filename) {
            if (!str_ends_with($filename, '.json')) {
                continue;
            }

            $langCode = pathinfo($filename, PATHINFO_FILENAME);
            $content = File\read($this->langDir . '/' . $filename);
            $this->translations[$langCode] = json_decode($content, true) ?? [];
            $this->loadedLanguages[] = $langCode;
        }

        if (!isset($this->translations[$this->defaultLanguage])) {
            $this->translations[$this->defaultLanguage] = [];
        }
    }

    public function get(string $key, ?string $lang = null, array $params = []): string
    {
        $lang = $lang ?? $this->defaultLanguage;

        $text = $this->translations[$lang][$key] ??
                $this->translations[$this->defaultLanguage][$key] ??
                $key;

        foreach ($params as $param => $value) {
            $text = str_replace('{' . $param . '}', $value, $text);
        }

        return $text;
    }

    public function getAvailableLanguages(): array
    {
        return $this->loadedLanguages;
    }

    public function getLanguageName(string $code): string
    {
        return $this->languageNames[$code] ?? $code;
    }

    public function reload(): void
    {
        $this->translations = [];
        $this->loadedLanguages = [];
        $this->loadAllLanguages();
    }

    public function addTranslation(string $lang, string $key, string $value): void
    {
        if (!isset($this->translations[$lang])) {
            $this->translations[$lang] = [];
        }
        $this->translations[$lang][$key] = $value;
    }

    public function saveLanguageFile(string $lang): bool
    {
        if (!isset($this->translations[$lang])) {
            return false;
        }

        if (!File\isDirectory($this->langDir)) {
            File\createDirectoryRecursively($this->langDir, 0755);
        }

        $file = $this->langDir . '/' . $lang . '.json';
        $json = json_encode($this->translations[$lang], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File\write($file, $json);

        return true;
    }

    public function getDefaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function setDefaultLanguage(string $lang): void
    {
        if (in_array($lang, $this->loadedLanguages, true)) {
            $this->defaultLanguage = $lang;
        }
    }
}
