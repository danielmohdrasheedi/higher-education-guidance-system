{{-- A list of requirements. A choice between options is shown as one item listing each option and its requirements. --}}
<ul class="{{ $listClass }}">
    @foreach (\App\Eligibility\ConditionResult::grouped($conditions) as $item)
        @if (isset($item['condition']))
            @include('eligibility.partials.condition', ['condition' => $item['condition'], 'withStatus' => $withStatus])
        @else
            <li class="condition-options">
                <span class="condition-options__intro">Meet one of these options:</span>
                <ol class="condition-options__list">
                    @foreach ($item['options'] as $number => $optionConditions)
                        <li class="condition-options__option">
                            <span class="condition-options__label">Option {{ $number }}</span>
                            <ul class="condition-options__conditions">
                                @foreach ($optionConditions as $condition)
                                    @include('eligibility.partials.condition', ['condition' => $condition, 'withStatus' => $withStatus])
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            </li>
        @endif
    @endforeach
</ul>
