<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesPlatformWide;

class ChatConversationPolicy
{
    use AuthorizesPlatformWide;

    protected function platformSuffix(): string
    {
        return 'chat::conversation';
    }
}
