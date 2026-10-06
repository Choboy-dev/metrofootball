  <header class="site-header" data-header>
    <nav class="site-nav" aria-label="Main">
      <a href="./" class="site-logo" aria-label="Metro Football home">
          <?php include __DIR__ . '/mf-logo-header.php'; ?>
        </a>

        <div class="nav-menu desktop-only">
          <div class="nav-item" data-nav-item>
            <button type="button" class="nav-trigger" data-nav-trigger aria-expanded="false" aria-haspopup="true">
              Tools
              <svg class="nav-caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nav-panel" data-nav-panel hidden>
              <div class="nav-panel__card">
                <ul class="nav-list nav-list--grid">
                  <li>
                    <a class="list-item" href="./pick" data-auth-tool>
                      <span class="list-item__copy">
                        <span class="list-item__title">Rate My Team</span>
                        <span class="list-item__desc">Score your squad and pick a captain</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./ai-tips">
                      <span class="list-item__copy">
                        <span class="list-item__title">AI Transfers</span>
                        <span class="list-item__desc">Get the best transfer for your squad</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./chip-strategy">
                      <span class="list-item__copy">
                        <span class="list-item__title">Chip Strategy Planner</span>
                        <span class="list-item__desc">Find the best weeks for each chip</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./ai-tips#snipers">
                      <span class="list-item__copy">
                        <span class="list-item__title">Differential Finder</span>
                        <span class="list-item__desc">Spot low owned high scorers</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./fixture-difficulty">
                      <span class="list-item__copy">
                        <span class="list-item__title">Fixture Difficulty Ticker</span>
                        <span class="list-item__desc">See easy and hard fixture runs</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./mini-leagues">
                      <span class="list-item__copy">
                        <span class="list-item__title">Mini League Tracker</span>
                        <span class="list-item__desc">Watch rivals and rank swings</span>
                      </span>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <div class="nav-item" data-nav-item>
            <button type="button" class="nav-trigger" data-nav-trigger aria-expanded="false" aria-haspopup="true">
              Reports
              <svg class="nav-caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nav-panel nav-panel--reports" data-nav-panel hidden>
              <div class="nav-company">
                <ul class="nav-list nav-list--stack">
                  <li>
                    <a class="list-item" href="./gameweek-recap">
                      <span class="list-item__copy">
                        <span class="list-item__title">Gameweek Recap</span>
                        <span class="list-item__desc">What just happened in FPL</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./price-changes">
                      <span class="list-item__copy">
                        <span class="list-item__title">Price Change Predictions</span>
                        <span class="list-item__desc">See who may rise or fall</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./fpl-injury-news">
                      <span class="list-item__copy">
                        <span class="list-item__title">Injury Risk Digest</span>
                        <span class="list-item__desc">Who may miss the next match</span>
                      </span>
                    </a>
                  </li>
                </ul>
                <ul class="nav-list nav-list--stack">
                  <li>
                    <a class="list-item" href="./players" aria-current="page">
                      <span class="list-item__copy">
                        <span class="list-item__title">Players</span>
                        <span class="list-item__desc">Search players and season stats</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="./league-table">
                      <span class="list-item__copy">
                        <span class="list-item__title">League Table</span>
                        <span class="list-item__desc">See the table and recent form</span>
                      </span>
                    </a>
                  </li>
                  <li>
                    <a class="list-item" href="/fpl-gameweek-tips" data-nav-gw-tips-link>
                      <span class="list-item__copy">
                        <span class="list-item__title" data-nav-gw-tips-label>Gameweek Tips</span>
                        <span class="list-item__desc">Picks and advice for this gameweek</span>
                      </span>
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <a data-pricing-nav class="nav-link" href="./join">Pricing</a>
          <a class="nav-link" href="./about">About</a>
          <a class="nav-link" href="./fpl-guides">FPL Guides</a>
          <a class="nav-link" href="./help-center">Help</a>
          <a class="nav-link" href="./contact">Contact</a>
        </div>

      <div class="site-nav__right desktop-only">
        <a class="mf-btn mf-btn--ink mf-btn--sm" href="./login" data-guest-only>
          <span class="mf-btn__label">Sign In</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <a class="mf-btn mf-btn--primary mf-btn--sm" href="./join" data-guest-only>
          <span class="mf-btn__label">Get Started</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <button type="button" class="mf-btn mf-btn--ink mf-btn--sm" data-auth-only data-sign-out hidden>
          <span class="mf-btn__label">Sign Out</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </button>
      </div>

      <button
        type="button"
        class="btn-nav btn-nav--icon mobile-only"
        data-mobile-toggle
        aria-expanded="false"
        aria-controls="mobile-menu"
        aria-label="Toggle menu"
      >
        <span class="menu-toggle-icon" data-menu-icon aria-hidden="true">
          <span></span><span></span><span></span>
        </span>
      </button>
    </nav>
  </header>

  <div id="mobile-menu" class="mobile-menu" data-mobile-menu hidden>
    <div class="mobile-menu__inner" data-mobile-inner>
      <div class="mobile-menu__scroll" data-mobile-scroll>
        <nav class="mobile-menu__nav" data-mobile-nav aria-label="Mobile navigation"></nav>
      </div>
      <div class="mobile-menu__actions">
        <a class="mf-btn mf-btn--ink mf-btn--block" href="./login" data-guest-only>
          <span class="mf-btn__label">Sign In</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <a class="mf-btn mf-btn--primary mf-btn--block" href="./join" data-guest-only>
          <span class="mf-btn__label">Get Started</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <button type="button" class="mf-btn mf-btn--ink mf-btn--block" data-auth-only data-sign-out hidden>
          <span class="mf-btn__label">Sign Out</span>
          <span class="mf-btn__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </button>
      </div>
    </div>
  </div>
