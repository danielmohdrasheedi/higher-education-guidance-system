<x-layout>
    <section class="hero">
        <div class="container hero__inner">
            <div class="hero__content">
                <h1 class="hero__title">Wondering what you can study?</h1>
                <p class="hero__lead">Add your results once to see which courses you qualify for and what you're missing.</p>
                <div class="hero__actions">
                    <a class="button button--dark button--large" href="{{ route('academic-profile.create') }}">Add your results</a>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <h2 class="section__title">Three steps</h2>

            <ol class="steps">
                <li class="step">
                    <span class="step__number">1</span>
                    <h3>Add your results</h3>
                    <p>Choose your qualification, then add your grades, and your CGPA and MUET band if you have them.</p>
                </li>
                <li class="step">
                    <span class="step__number">2</span>
                    <h3>See what you qualify for</h3>
                    <p>Your results are checked against every course's entry requirements. If you don't qualify, you'll see why and find similar courses you do qualify for.</p>
                </li>
                <li class="step">
                    <span class="step__number">3</span>
                    <h3>Choose your courses</h3>
                    <p>Save courses to your favourites to compare them, and see past applications, offers and merit scores.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="section section--flush">
        <div class="container">
            <div class="banner">
                <div>
                    <h2 class="banner__title">Your results stay private</h2>
                    <p>Your results are kept only while you use the site. They're never saved, and you can delete them at any time.</p>
                </div>
            </div>
        </div>
    </section>
</x-layout>
