<?php
function icon(string $name): string
{
    static $map = [
        'grid' => 'M3 3h7v7H3V3zm11 0h7v7h-7V3zM3 14h7v7H3v-7zm11 7v-7h7v7h-7z',
        'activity' => 'M22 12h-4l-3 7L9 5l-3 7H2',
        'layers' => 'M12 2l9 5-9 5-9-5 9-5zm0 9l9 5-9 5-9-5 9-5z',
        'tag' => 'M20 12l-8 8-8-8V4h8l8 8z',
        'box' => 'M3 7l9-4 9 4v10l-9 4-9-4V7zm9-4v10',
        'edit' => 'M12 20h9M3 21h18M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z',
        'checklist' => 'M9 11l3 3L22 4M4 7h5l2-3h6l2 3h1a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z',
        'clipboard' => 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2',
        'settings' => 'M12 1v3m0 16v3m11-11h-3M4 12H1m19.4-7.4l-2.1 2.1M6.7 17.3l-2.1 2.1m0-14.8l2.1 2.1m12.6 12.6l2.1 2.1M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z',
        'bell' => 'M18 8a6 6 0 10-12 0v5l-2 3h16l-2-3V8M9 21h6',
        'search' => 'M21 21l-4.3-4.3M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z',
        'chev' => 'M9 6l6 6-6 6',
        'database' => 'M12 3c-4.97 0-9 1.79-9 4v10c0 2.21 4.03 4 9 4s9-1.79 9-4V7c0-2.21-4.03-4-9-4zm0 2c3.87 0 7 1.12 7 2s-3.13 2-7 2-7-1.12-7-2 3.13-2 7-2zm0 14c-3.87 0-7-1.12-7-2v-1.35C6.19 16.62 8.91 17 12 17s5.81-.38 7-1.35V17c0 .88-3.13 2-7 2zm0-6c-3.87 0-7-1.12-7-2V9.65C6.19 10.62 8.91 11 12 11s5.81-.38 7-1.35V11c0 .88-3.13 2-7 2z',
        'upload' => 'M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M7 9l5-5 5 5M12 4v12',
    ];

    $path = $map[$name] ?? '';

    return sprintf(
        "<svg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='%s'/></svg>",
        $path
    );
}
