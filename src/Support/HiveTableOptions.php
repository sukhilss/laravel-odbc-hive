<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Support;

/**
 * Hive-specific CREATE TABLE options.
 *
 * Replaces the dynamic Blueprint properties used before v7 ($blueprint->format,
 * ->location, ->delimiter, ->charset), which relied on dynamic property creation
 * deprecated in PHP 8.2.
 */
final class HiveTableOptions
{
    private ?string $charset = null;

    private ?string $storedAs = null;

    private ?string $delimiter = null;

    private ?string $location = null;

    public function charset(): ?string
    {
        return $this->charset;
    }

    public function storedAs(): ?string
    {
        return $this->storedAs;
    }

    public function delimiter(): ?string
    {
        return $this->delimiter;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function setCharset(?string $charset): self
    {
        $this->charset = $charset;

        return $this;
    }

    public function setStoredAs(?string $format): self
    {
        $this->storedAs = $format;

        return $this;
    }

    public function setDelimiter(?string $delimiter): self
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->charset === null
            && $this->storedAs === null
            && $this->delimiter === null
            && $this->location === null;
    }
}
