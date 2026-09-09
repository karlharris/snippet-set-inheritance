<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Config;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typed accessor for the plugin's system config (Resources/config/config.xml).
 */
class PluginConfig
{
    public const string FALLBACK_SNIPPET_SET_ID = 'ScytheSnippetSetInheritance.config.fallbackSnippetSetId';
    public const string MAX_INHERITANCE_DEPTH = 'ScytheSnippetSetInheritance.config.maxInheritanceDepth';

    public const int DEFAULT_MAX_DEPTH = 10;

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getFallbackSnippetSetId(): ?string
    {
        $value = $this->systemConfigService->getString(self::FALLBACK_SNIPPET_SET_ID);

        return $value !== '' && Uuid::isValid($value) ? $value : null;
    }

    public function getMaxInheritanceDepth(): int
    {
        $value = $this->systemConfigService->getInt(self::MAX_INHERITANCE_DEPTH);

        return $value >= 1 ? $value : self::DEFAULT_MAX_DEPTH;
    }
}
