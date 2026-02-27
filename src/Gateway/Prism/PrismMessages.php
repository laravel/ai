<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio as PrismAudio;
use Prism\Prism\ValueObjects\Media\Document as PrismDocument;
use Prism\Prism\ValueObjects\Media\Image as PrismImage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage as PrismAssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage as PrismToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage as PrismUserMessage;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;

class PrismMessages
{
    /**
     * Marshal the given Laravel AI SDK messages into Prism messages.
     */
    public static function fromLaravelMessages(Collection $messages): Collection
    {
        return $messages
            ->map(function ($message) {
                if ($message instanceof ToolResultMessage) {
                    return new PrismToolResultMessage(
                        $message->toolResults->map(fn ($toolResult) => new PrismToolResult(
                            toolCallId: $toolResult->id,
                            toolName: $toolResult->name,
                            args: $toolResult->arguments,
                            result: $toolResult->result,
                            toolCallResultId: $toolResult->resultId,
                        ))->all()
                    );
                }

                $message = Message::tryFrom($message);

                if ($message->role === MessageRole::User) {
                    return new PrismUserMessage(
                        $message->content,
                        additionalContent: static::fromLaravelAttachments($message->attachments ?? new Collection)->all(),
                    );
                }

                if ($message->role === MessageRole::Assistant) {
                    $toolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()
                        ? $message->toolCalls->map(fn ($toolCall) => new PrismToolCall(
                            id: $toolCall->id,
                            name: $toolCall->name,
                            arguments: $toolCall->arguments,
                            resultId: $toolCall->resultId,
                            reasoningId: $toolCall->reasoningId,
                            reasoningSummary: $toolCall->reasoningSummary,
                        ))->all()
                        : [];

                    return new PrismAssistantMessage(
                        $message->content ?? '',
                        toolCalls: $toolCalls,
                    );
                }
            })->filter()->values();
    }

    /**
     * Marshal the given Laravel message attachments to Prism message attachments.
     */
    protected static function fromLaravelAttachments(Collection $attachments): Collection
    {
        return $attachments->map(function ($attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.$attachment::class.']'
                );
            }

            $prismAttachment = match (true) {
                $attachment instanceof ProviderImage => PrismImage::fromFileId($attachment->id),
                $attachment instanceof Base64Image => PrismImage::fromBase64($attachment->base64, $attachment->mime),
                $attachment instanceof LocalImage => PrismImage::fromLocalPath($attachment->path, $attachment->mime),
                $attachment instanceof RemoteImage => PrismImage::fromUrl($attachment->url),
                $attachment instanceof StoredImage => PrismImage::fromStoragePath($attachment->path, $attachment->disk),
                $attachment instanceof ProviderDocument => PrismDocument::fromFileId($attachment->id),
                $attachment instanceof Base64Document => PrismDocument::fromBase64($attachment->base64, $attachment->mime),
                $attachment instanceof LocalDocument => PrismDocument::fromPath($attachment->path),
                $attachment instanceof RemoteDocument => PrismDocument::fromUrl($attachment->url),
                $attachment instanceof StoredDocument => PrismDocument::fromStoragePath($attachment->path, $attachment->disk),
                $attachment instanceof UploadedFile && static::isImage($attachment) => PrismImage::fromBase64(base64_encode($attachment->get()), $attachment->getClientMimeType()),
                $attachment instanceof UploadedFile && static::isAudio($attachment) => PrismAudio::fromBase64(base64_encode($attachment->get()), $attachment->getClientMimeType()),
                $attachment instanceof UploadedFile => PrismDocument::fromBase64(base64_encode($attachment->get()), $attachment->getClientMimeType()),
            };

            if ($attachment instanceof File && $attachment->name) {
                $prismAttachment->as($attachment->name);
            }

            return $prismAttachment;
        });
    }

    /**
     * Determine if the given uploaded file attachment is an image.
     */
    protected static function isAudio(UploadedFile $attachment): bool
    {
        return in_array($attachment->getClientMimeType(), [
            'audio/mpeg',
            'audio/wav',
            'audio/x-wav',
            'audio/aac',
            'audio/opus',
        ]);
    }

    /**
     * Determine if the given uploaded file attachment is an image.
     */
    protected static function isImage(UploadedFile $attachment): bool
    {
        return in_array($attachment->getClientMimeType(), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);
    }

    /**
     * Marshal the given Prism messages to Laravel AI SDK messages.
     */
    public static function toLaravelMessages(Collection $messages): Collection
    {
        return $messages->map(function ($message) {
            if ($message instanceof PrismUserMessage) {
                return new UserMessage($message->content);
            }

            if ($message instanceof PrismAssistantMessage) {
                return new AssistantMessage(
                    $message->content ?? '',
                    toolCalls: (new Collection($message->toolCalls ?? []))
                        ->map(PrismTool::toLaravelToolCall(...))
                );
            }

            if ($message instanceof PrismToolResultMessage) {
                return new ToolResultMessage(
                    (new Collection($message->toolResults))
                        ->map(PrismTool::toLaravelToolResult(...))
                );
            }

            return $message;
        })->values();
    }
}
