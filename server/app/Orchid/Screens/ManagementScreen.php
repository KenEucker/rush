<?php

namespace App\Orchid\Screens;

use Orchid\Screen\Screen;

abstract class ManagementScreen extends Screen
{
    public function screenBaseView(): string
    {
        return 'admin.screen';
    }
}
