<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Core\System\Snippet;

use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Snippet\Aggregate\SnippetSet\SnippetSetDefinition;

/**
 * Adds a self-referencing, nullable `parentId` to the core `snippet_set` entity.
 * The backing column + FK (`ON DELETE SET NULL`) is created by
 * {@see \Scythe\SnippetSetInheritance\Migration\Migration1789200000AddParentIdToSnippetSet}.
 *
 * The fields are added without the `Extension` flag (like core's own
 * `FkFieldExtension` test fixture) so `parentId` stays a plain top-level Admin
 * API attribute the snippet-set grid can bind to directly. The `snippet_set`
 * entity class has no matching property, so on the PHP side the values live in
 * the entity's `extensions` bag.
 */
#[Package('discovery')]
class SnippetSetExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new FkField('parent_id', 'parentId', SnippetSetDefinition::class))->addFlags(new ApiAware())
        );

        $collection->add(
            (new ManyToOneAssociationField('parent', 'parent_id', SnippetSetDefinition::class, 'id', false))->addFlags(new ApiAware())
        );

        $collection->add(
            (new OneToManyAssociationField('children', SnippetSetDefinition::class, 'parent_id', 'id'))->addFlags(new ApiAware())
        );
    }

    public function getEntityName(): string
    {
        return SnippetSetDefinition::ENTITY_NAME;
    }
}
