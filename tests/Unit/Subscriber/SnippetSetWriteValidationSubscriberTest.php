<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scythe\SnippetSetInheritance\Inheritance\SnippetInheritanceResolver;
use Scythe\SnippetSetInheritance\Subscriber\SnippetSetWriteValidationSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\Aggregate\SnippetSet\SnippetSetDefinition;

#[CoversClass(SnippetSetWriteValidationSubscriber::class)]
class SnippetSetWriteValidationSubscriberTest extends TestCase
{
    public function testSubscribedToPreWriteValidationEvent(): void
    {
        static::assertSame(
            [PreWriteValidationEvent::class => 'validate'],
            SnippetSetWriteValidationSubscriber::getSubscribedEvents()
        );
    }

    public function testRejectsSelfReference(): void
    {
        $id = Uuid::randomHex();

        $event = $this->eventFor([
            $this->command(UpdateCommand::class, $id, ['parent_id' => Uuid::fromHexToBytes($id)]),
        ]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(1, $event->getExceptions()->getExceptions());
    }

    public function testRejectsCycle(): void
    {
        $id = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $event = $this->eventFor([
            $this->command(UpdateCommand::class, $id, ['parent_id' => Uuid::fromHexToBytes($parentId)]),
        ]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(1, $event->getExceptions()->getExceptions());
    }

    public function testAcceptsValidParent(): void
    {
        $id = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $event = $this->eventFor([
            $this->command(UpdateCommand::class, $id, ['parent_id' => Uuid::fromHexToBytes($parentId)]),
        ]);

        $this->subscriber(cycle: false)->validate($event);

        static::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testAcceptsClearingParent(): void
    {
        $event = $this->eventFor([
            $this->command(UpdateCommand::class, Uuid::randomHex(), ['parent_id' => null]),
        ]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testIgnoresWritesWithoutParentIdInPayload(): void
    {
        $event = $this->eventFor([
            $this->command(UpdateCommand::class, Uuid::randomHex(), ['name' => Uuid::randomHex()]),
        ]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testIgnoresOtherEntities(): void
    {
        $command = $this->command(UpdateCommand::class, Uuid::randomHex(), ['parent_id' => Uuid::fromHexToBytes(Uuid::randomHex())], 'snippet');

        $event = $this->eventFor([$command]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testInsertWithNewParentIsChecked(): void
    {
        $id = Uuid::randomHex();

        $event = $this->eventFor([
            $this->command(InsertCommand::class, $id, ['parent_id' => Uuid::fromHexToBytes($id)]),
        ]);

        $this->subscriber(cycle: true)->validate($event);

        static::assertCount(1, $event->getExceptions()->getExceptions());
    }

    private function subscriber(bool $cycle): SnippetSetWriteValidationSubscriber
    {
        $resolver = $this->createStub(SnippetInheritanceResolver::class);
        $resolver->method('wouldCreateCycleOrSelfReference')->willReturn($cycle);

        return new SnippetSetWriteValidationSubscriber($resolver);
    }

    /**
     * @param class-string<WriteCommand> $class
     * @param array<string, mixed> $payload
     */
    private function command(string $class, string $primaryKeyHex, array $payload, string $entityName = SnippetSetDefinition::ENTITY_NAME): WriteCommand
    {
        $command = $this->createStub($class);

        $command->method('getEntityName')->willReturn($entityName);
        $command->method('getPayload')->willReturn($payload);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::fromHexToBytes($primaryKeyHex)]);
        $command->method('getPath')->willReturn('/0');

        return $command;
    }

    /**
     * @param list<WriteCommand> $commands
     */
    private function eventFor(array $commands): PreWriteValidationEvent
    {
        return new PreWriteValidationEvent(
            WriteContext::createFromContext(Context::createDefaultContext()),
            $commands
        );
    }
}
