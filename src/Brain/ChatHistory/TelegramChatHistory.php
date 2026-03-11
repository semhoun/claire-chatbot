<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Entity\ChatHistory;
use App\Entity\User;
use Doctrine\ORM\EntityManager;
use NeuronAI\Chat\History\AbstractChatHistory;

class TelegramChatHistory extends AbstractChatHistory
{
    public function __construct(
        protected string $userId,
        protected string $chatId,
        protected EntityManager $entityManager,
        protected int $contextWindow = 50000
    ) {
        parent::__construct($contextWindow);
        $this->load();
    }

    #[\Override]
    protected function load(): void
    {
        $entityRepository = $this->entityManager->getRepository(ChatHistory::class);
        $chatHistory = $entityRepository->findOneBy(['threadId' => $this->chatId]);

        if ($chatHistory instanceof ChatHistory) {
            $messages = \json_decode($chatHistory->getMessages(), true, flags: JSON_THROW_ON_ERROR);
            $this->history = $this->deserializeMessages($messages);
        } else {
            $chatHistory = new ChatHistory();
            $chatHistory->setThreadId($this->chatId);
            $chatHistory->setMessages('[]');

            $user = $this->findUser();
            if ($user instanceof User) {
                $chatHistory->setUser($user);
            }

            $this->entityManager->persist($chatHistory);
            $this->entityManager->flush();
        }
    }

    #[\Override]
    protected function setMessages(array $messages): void
    {
        $entityRepository = $this->entityManager->getRepository(ChatHistory::class);
        $chatHistory = $entityRepository->findOneBy(['threadId' => $this->chatId]);

        if (! $chatHistory instanceof ChatHistory) {
            $chatHistory = new ChatHistory();
            $chatHistory->setThreadId($this->chatId);

            $user = $this->findUser();
            if ($user instanceof User) {
                $chatHistory->setUser($user);
            }
        }

        $chatHistory->setMessages(\json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR));
        $this->entityManager->persist($chatHistory);
        $this->entityManager->flush();
    }

    #[\Override]
    protected function clear(): void
    {
        $entityRepository = $this->entityManager->getRepository(ChatHistory::class);
        $chatHistory = $entityRepository->findOneBy(['threadId' => $this->chatId]);

        if ($chatHistory instanceof ChatHistory) {
            $this->entityManager->remove($chatHistory);
            $this->entityManager->flush();
        }
    }

    private function findUser(): ?User
    {
        $telegramId = \str_starts_with($this->userId, 'tg_')
            ? \substr($this->userId, 3)
            : $this->userId;

        return $this->entityManager->getRepository(User::class)
            ->findByTelegramId($telegramId);
    }
}
