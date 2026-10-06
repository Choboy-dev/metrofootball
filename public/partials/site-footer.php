  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand">
        <a class="brand" href="./">
          <?php include __DIR__ . '/mf-logo-footer.php'; ?>
        </a>
        <p>Metro Football builds FPL tools and auto reports for managers who want clear answers before the deadline.</p>
        <?php
        $root = dirname(__DIR__, 2);
        require_once $root . '/app/Env.php';
        require_once $root . '/app/SocialLinks.php';
        $mfSocialVariant = 'footer';
        include __DIR__ . '/mf-social.php';
        ?>
      </div>
      <div class="footer-col">
        <h4>Explore</h4>
        <a href="./">Home</a>
        <a href="./players">Players</a>
        <a href="./help-center">Help Center</a>
        <a href="./fpl-guides">FPL Guides</a>
        <a href="./league-table">League Table</a>
        <a href="./live">Dashboard</a>
        <a href="./join">Upgrade</a>
      </div>
      <div class="footer-col">
        <h4>Tools</h4>
        <a href="./fixture-difficulty">Fixture Difficulty Ticker</a>
        <a href="./ai-tips#snipers">Differential Finder</a>
        <a href="./mini-leagues">Mini League Tracker</a>
        <a href="./pick">Rate My Team</a>
        <a href="./ai-tips">Transfer Planner</a>
        <a href="./ai-tips">AI Transfers</a>
        <a href="./chip-strategy">Chip Strategy Planner</a>
      </div>
    </div>
    <div class="container footer-bottom">
      <span>© 2026 Metro Football. metrofootball.co.uk</span>
      <span>
        <a href="./privacy-policy">Privacy Policy</a>
        ·
        <a href="./terms">Terms of Service</a>
        ·
        <a href="./disclaimer">Disclaimer</a>
        ·
        <a href="./about">About Us</a>
        ·
        <a href="./contact">Contact Us</a>
      </span>
    </div>
  </footer>
