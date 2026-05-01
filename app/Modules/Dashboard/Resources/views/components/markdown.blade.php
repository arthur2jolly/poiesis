@php
    /**
     * Renders a Markdown source string as safe HTML inside a `prose` wrapper.
     *
     * Variables (passed via @include('dashboard::components.markdown', [...])):
     * - $source : string|null — Markdown source. Empty string or null → renders nothing.
     * - $plain  : bool        — true to strip Markdown to plain text (for clamped previews). Default false.
     * - $class  : string      — additional CSS classes appended to the wrapper. Default ''.
     */
    $source ??= null;
    $plain ??= false;
    $class ??= '';
    $raw = (string) ($source ?? '');
@endphp

@if ($raw === '')
{{-- nothing to render --}}
@elseif ($plain)
<span class="{{ $class }}">{{ trim(strip_tags(\Illuminate\Support\Str::markdown($raw, [
    'html_input' => 'escape',
    'allow_unsafe_links' => false,
]))) }}</span>
@else
<div class="prose prose-sm max-w-none {{ $class }}">
{!! \Illuminate\Support\Str::markdown($raw, [
    'html_input' => 'escape',
    'allow_unsafe_links' => false,
]) !!}
</div>
@endif
