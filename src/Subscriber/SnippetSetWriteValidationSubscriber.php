<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Subscriber;

use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\Snippet\Aggregate\SnippetSet\SnippetSetDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Rejects a `snippet_set.parent_id` write that would make a set inherit from
 * itself or form a cycle. On the pre-write validation event so it covers every
 * write path (admin inline edit, generic admin API, sync API, imports).
 */
class SnippetSetWriteValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SnippetInheritanceResolver $resolver)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'validate',
        ];
    }

    public function validate(PreWriteValidationEvent $event): void
    {
        $violations = new ConstraintViolationList();

        foreach ($event->getCommandsForEntity(SnippetSetDefinition::ENTITY_NAME) as $command) {
            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                continue;
            }

            $payload = $command->getPayload();
            if (!\array_key_exists('parent_id', $payload)) {
                continue;
            }

            $primaryKey = $command->getPrimaryKey();
            if (!isset($primaryKey['id'])) {
                continue;
            }

            $id = Uuid::fromBytesToHex($primaryKey['id']);
            $parentId = $payload['parent_id'] === null ? null : Uuid::fromBytesToHex($payload['parent_id']);

            if ($parentId === null || !$this->resolver->wouldCreateCycleOrSelfReference($id, $parentId)) {
                continue;
            }

            $message = $parentId === $id
                ? 'A snippet set cannot inherit from itself.'
                : 'This parent assignment would create a cycle in the snippet set inheritance chain.';

            $violations->add(new ConstraintViolation(
                $message,
                $message,
                [],
                null,
                '/' . $command->getPath() . '/parentId',
                $parentId
            ));
        }

        if ($violations->count() > 0) {
            $event->getExceptions()->add(new WriteConstraintViolationException($violations));
        }
    }
}
