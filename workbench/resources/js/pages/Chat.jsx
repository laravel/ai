import { useState, useRef } from 'react';
import {
    MessageScrollerProvider,
    MessageScroller,
    MessageScrollerViewport,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerButton,
} from '@/components/ui/message-scroller';
import { Message, MessageContent } from '@/components/ui/message';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Marker, MarkerContent } from '@/components/ui/marker';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

export default function Chat() {
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const conversationId = useRef(null);

    async function send(e) {
        e.preventDefault();

        const text = input.trim();

        if (!text || loading) {
            return;
        }

        setMessages((prev) => [...prev, { id: crypto.randomUUID(), role: 'user', content: text }]);
        setInput('');
        setLoading(true);

        try {
            const response = await fetch('/chat/message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    message: text,
                    conversation_id: conversationId.current,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message ?? 'Something went wrong.');
            }

            conversationId.current = data.conversation_id;

            setMessages((prev) => [
                ...prev,
                { id: crypto.randomUUID(), role: 'assistant', content: data.text },
            ]);
        } catch (error) {
            setMessages((prev) => [
                ...prev,
                { id: crypto.randomUUID(), role: 'assistant', content: `Error: ${error.message}` },
            ]);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="mx-auto flex h-screen max-w-2xl flex-col p-4">
            <h1 className="mb-4 text-lg font-semibold">Laravel AI SDK — Workbench Chat</h1>

            <MessageScrollerProvider>
                <MessageScroller className="min-h-0 flex-1 rounded-lg border border-border">
                    <MessageScrollerViewport>
                        <MessageScrollerContent className="p-4">
                            {messages.length === 0 && (
                                <Marker variant="separator">
                                    <MarkerContent>Say hello to start a conversation</MarkerContent>
                                </Marker>
                            )}

                            {messages.map((message, index) => (
                                <MessageScrollerItem
                                    key={message.id}
                                    scrollAnchor={index === messages.length - 1}
                                >
                                    <Message align={message.role === 'user' ? 'end' : 'start'}>
                                        <MessageContent>
                                            <Bubble
                                                align={message.role === 'user' ? 'end' : 'start'}
                                                variant={message.role === 'user' ? 'default' : 'secondary'}
                                            >
                                                <BubbleContent>{message.content}</BubbleContent>
                                            </Bubble>
                                        </MessageContent>
                                    </Message>
                                </MessageScrollerItem>
                            ))}

                            {loading && (
                                <MessageScrollerItem scrollAnchor>
                                    <Marker>
                                        <MarkerContent className="shimmer">Thinking…</MarkerContent>
                                    </Marker>
                                </MessageScrollerItem>
                            )}
                        </MessageScrollerContent>
                    </MessageScrollerViewport>

                    <MessageScrollerButton />
                </MessageScroller>
            </MessageScrollerProvider>

            <form onSubmit={send} className="mt-4 flex gap-2">
                <Input
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    placeholder="Type a message…"
                    autoFocus
                />
                <Button type="submit" disabled={loading}>
                    Send
                </Button>
            </form>
        </div>
    );
}
