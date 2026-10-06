/**
 * Motion — self-hosted bundle with timeout and safe fallbacks.
 */

const NOOP = () => {};
const NOOP_STAGGER = () => 0;

function stubMotion() {
  return { animate: NOOP, inView: NOOP, stagger: NOOP_STAGGER };
}

function importWithTimeout(specifier, ms) {
  return Promise.race([
    import(specifier),
    new Promise((_, reject) => {
      window.setTimeout(() => reject(new Error('motion import timeout')), ms);
    }),
  ]);
}

async function loadMotionApi() {
  try {
    return await importWithTimeout('./vendor/motion.esm.js', 10000);
  } catch {
    try {
      return await importWithTimeout(
        'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm',
        6000
      );
    } catch {
      return stubMotion();
    }
  }
}

const motionApi = await loadMotionApi();

export const animate = typeof motionApi.animate === 'function' ? motionApi.animate : NOOP;
export const inView = typeof motionApi.inView === 'function' ? motionApi.inView : NOOP;
export const stagger = typeof motionApi.stagger === 'function' ? motionApi.stagger : NOOP_STAGGER;
