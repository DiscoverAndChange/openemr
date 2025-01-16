<?php
namespace OpenEMR\Events\Codes;

use Symfony\Contracts\EventDispatcher\Event;

class CodeImportEvent extends Event
{
    const EVENT_NAME = 'code_types.import';
    private $codeType;
    private $filePath;
    private $isReplace;
    private $isHandled = false;
    private $messages = [];

    public function __construct(string $codeType, string $filePath, bool $isReplace)
    {
        $this->codeType = $codeType;
        $this->filePath = $filePath;
        $this->isReplace = $isReplace;
    }

    public function getCodeType(): string
    {
        return $this->codeType;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function isReplace(): bool
    {
        return $this->isReplace;
    }

    public function setHandled(bool $handled): void
    {
        $this->isHandled = $handled;
        $this->stopPropagation(); // don't do any more event handling if we've handled the event
    }

    public function isHandled(): bool
    {
        return $this->isHandled;
    }

    public function addMessage(string $type, string $message): void
    {
        if (!in_array($type, ['success', 'error'])) {
            throw new \InvalidArgumentException('Invalid message type');
        }
        if (empty($this->messages[$type])) {
            $this->messages[$type] = [];
        }
        $this->messages[$type][] = $message;
    }

    public function getMessages(string $type = ''): array
    {
        return $this->messages[$type] ?? [];
    }
}
