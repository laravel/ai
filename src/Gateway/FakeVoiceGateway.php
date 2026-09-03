<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\VoiceGateway;
use Laravel\Ai\Contracts\Providers\VoiceProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Voice;
use Laravel\Ai\Responses\VoicesResponse;

class FakeVoiceGateway implements VoiceGateway
{
    /**
     * @param  Closure|array<int, Voice>|null  $voices
     */
    public function __construct(
        protected Closure|array|null $voices = null,
    ) {}

    /**
     * List the voices available for audio generation.
     */
    public function listVoices(
        VoiceProvider $provider,
        int $timeout = 30,
    ): VoicesResponse {
        $voices = $this->voices instanceof Closure
            ? call_user_func($this->voices, $provider)
            : $this->voices;

        $voices ??= [
            new Voice('fake-female-voice', 'Fake Female Voice', 'female', ['en']),
            new Voice('fake-male-voice', 'Fake Male Voice', 'male', ['en']),
        ];

        return new VoicesResponse($voices, new Meta($provider->name()));
    }
}
