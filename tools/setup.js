import { systemCmd } from './lib/system.js'

(async () => {
  try {
    await systemCmd('pnpm install --frozen-lockfile');
    await systemCmd('composer install');
  } catch (err) {
    console.log(err);
  }
})();
