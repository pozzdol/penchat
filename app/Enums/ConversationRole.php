<?php

namespace App\Enums;

/**
 * Roles exist only inside groups. A direct chat has two equals and its pivot
 * rows keep the default; nothing ever reads the role there.
 */
enum ConversationRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
