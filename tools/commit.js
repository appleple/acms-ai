import co from 'co'
import { systemCmd } from './lib/system.js'
import pkg from '../package.json' with { type: 'json' };

co(function* () {
  try {
    yield systemCmd('git add -A');
    yield systemCmd(`git commit -m "v${pkg.version}"`);
    yield systemCmd(`git tag v${pkg.version}`);
    yield systemCmd('git push');
    yield systemCmd('git push --tags');
  } catch (err) {
    console.log(err);
  }
});
