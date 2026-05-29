import fs from 'fs-extra'
import pkg from '../package.json' with { type: 'json' };
const serviceProvider = './app/ServiceProvider.php';

try {
  let appCode = fs.readFileSync(serviceProvider, 'utf-8');
  appCode = appCode.replace(/\$version =\s*'.+';/, `$version = '${pkg.version}';`);
  fs.writeFileSync(serviceProvider, appCode);
} catch (err) {
  console.log(err);
}
