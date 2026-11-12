{{-- A heart: outlined adds the course to favourites, filled removes it. public/js/favourites.js sends it without reloading the page --}}
<form
    method="POST"
    action="{{ $isFavourite ? route('favourites.destroy', $programme->programme_code) : route('favourites.store', $programme->programme_code) }}"
    data-favourite-form
    data-add-url="{{ route('favourites.store', $programme->programme_code) }}"
    data-remove-url="{{ route('favourites.destroy', $programme->programme_code) }}"
    data-course-name="{{ $programme->programme_name }}, {{ $programme->institution_name }}"
>
    @csrf
    <input type="hidden" name="_method" value="DELETE" @disabled(! $isFavourite)>
    <button
        @class(['favourite-button', 'is-favourite' => $isFavourite])
        type="submit"
        aria-label="{{ $isFavourite ? 'Remove' : 'Add' }} {{ $programme->programme_name }}, {{ $programme->institution_name }} {{ $isFavourite ? 'from' : 'to' }} favourites"
        title="{{ $isFavourite ? 'Remove from favourites' : 'Add to favourites' }}"
    >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path d="M12 20.5 4.1 12.9a4.9 4.9 0 0 1 0-7.1 5.1 5.1 0 0 1 7.2 0l.7.7.7-.7a5.1 5.1 0 0 1 7.2 0 4.9 4.9 0 0 1 0 7.1Z" />
        </svg>
    </button>
</form>
