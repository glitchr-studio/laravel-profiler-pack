{{-- The bar, written before the page's </body>: the markup of Symfony's web
     debug toolbar (the same classes, so that its styles, the floating ones and
     what pages already set aside for it all apply), its styles and scripts
     inline. The root carries data-cookie-ignore and data-consent-ignore: a
     cookie panel that closes on a click elsewhere leaves the bar's clicks
     alone. --}}
<!-- START of the Laravel profiler bar (glitchr/laravel-profiler-pack) -->
<style @if($nonce) nonce="{{ $nonce }}" @endif>
{!! $styles !!}
@if($hideWhen)
{!! $hideWhen !!} .sf-toolbar { display: none !important; }
@endif
</style>
<div class="sf-toolbar sf-toolbar-opened" role="toolbar" aria-label="Laravel profiler bar" data-laravel-profiler-pack="{{ json_encode($options) }}" data-cookie-ignore data-consent-ignore>
    <div id="sfwdt{{ $token }}">
        <div id="sfToolbarClearer-{{ $token }}" class="sf-toolbar-clearer"></div>
        <div id="sfToolbarMainContent-{{ $token }}" class="sf-toolbarreset notranslate clear-fix" data-no-turbolink data-turbo="false">
            @foreach($blocks as $block)
                @php($info = 'sf-toolbar-info-'.$block['name'].'-'.$token)
                <div class="sf-toolbar-block sf-toolbar-block-{{ $block['name'] }} sf-toolbar-status-{{ $block['status'] ?? 'normal' }}{{ !empty($block['right']) ? ' sf-toolbar-block-right' : '' }}" data-accessible-label="{{ ucfirst(str_replace('-', ' ', $block['name'])) }}">
                    @if($block['link'])<a href="{{ $block['link'] }}" aria-controls="{{ $info }}" aria-haspopup="dialog">@endif
                        <div class="sf-toolbar-icon">
                            @if(isset($block['badge']))
                                <span class="sf-toolbar-status sf-toolbar-status-{{ $block['status'] ?? 'normal' }}">{{ $block['badge'] }}</span>
                                @if('' !== $block['label'])<span class="sf-toolbar-label">{{ $block['label'] }}</span>@endif
                                <span class="sf-toolbar-value{{ !empty($block['value_additional']) ? ' sf-toolbar-info-piece-additional' : '' }}">{{ $block['value'] }}</span>
                            @else
                                {!! $icons($block['icon'] ?? 'other') !!}
                                <span class="sf-toolbar-value">{{ $block['value'] }}</span>
                                @if('' !== $block['label'])<span class="sf-toolbar-label">{{ $block['label'] }}</span>@endif
                            @endif
                        </div>
                    @if($block['link'])</a>@endif
                    <div class="sf-toolbar-info" id="{{ $info }}" role="dialog" aria-roledescription="details" aria-label="{{ ucfirst(str_replace('-', ' ', $block['name'])) }}" tabindex="-1">
                        @foreach($block['groups'] as $group)
                            <div class="sf-toolbar-info-group">
                                @foreach($group as $piece)
                                    <div class="sf-toolbar-info-piece">
                                        <b>@if(!empty($piece[3]))<span class="sf-toolbar-status sf-toolbar-status-{{ $piece[3] }}">{{ $piece[0] }}</span>@else{{ $piece[0] }}@endif</b>
                                        <span>@if(!empty($piece[2]))<a href="{{ $piece[2] }}">{{ $piece[1] }}</a>@else{{ $piece[1] }}@endif</span>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <button class="sf-toolbar-toggle-button" type="button" id="sfToolbarToggleButton-{{ $token }}" accesskey="D" aria-expanded="true" aria-controls="sfToolbarMainContent-{{ $token }}" aria-label="Toggle the profiler bar">
                <i class="sf-toolbar-icon-opened" title="Close the bar">{!! $icons('close') !!}</i>
                <i class="sf-toolbar-icon-closed" title="Open the bar">{!! $icons('telescope') !!}</i>
            </button>
        </div>
    </div>
</div>
<script @if($nonce) nonce="{{ $nonce }}" @endif>
{!! $script !!}
</script>
@if($floating)
<script @if($nonce) nonce="{{ $nonce }}" @endif>
{!! $floating !!}
</script>
@endif
<!-- END of the Laravel profiler bar -->
