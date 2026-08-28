/**
 * Self-hosted Motion — loads the UMD build once, re-exports ESM API.
 */
import './vendor/motion.umd.js';

const motion = globalThis.Motion;
if (!motion) {
  throw new Error('Motion failed to load from ./vendor/motion.umd.js — run scripts/setup-performance-assets.php');
}

export const { animate, inView, stagger } = motion;
