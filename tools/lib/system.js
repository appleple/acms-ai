import cmd from 'node-cmd'
import fs from 'fs-extra'
import archiver from 'archiver'

/**
 * Run system command
 *
 * @param cmdString
 * @returns {Promise}
 */
export const systemCmd = cmdString => (
  new Promise((resolve) => {
    const process = cmd.run(cmdString);
    let data = '';

    process.stdout.on('data', (chunk) => {
      data += chunk;
    });

    process.stderr.on('data', (chunk) => {
      console.log('stderr:', chunk);
    });

    process.on('close', (code) => {
      console.log(cmdString);
      console.log(data);
      if (code !== 0) {
        console.log(`Process exited with code ${code}`);
      }
      resolve(data);
    });
  })
)

export const systemDirList = directory => (
  new Promise((resolve) => {
    fs.readdir(directory, (err, files) => {
      if (err) throw err;
      resolve(files);
    });
  })
)

export const zipPromise = (src, dist) => (
  new Promise((resolve, reject) => {
    const archive = archiver.create('zip', {});
    const output = fs.createWriteStream(dist);

    // listen for all archive data to be written
    output.on('close', () => {
      console.log(archive.pointer() + ' total bytes');
      console.log('Archiver has been finalized and the output file descriptor has closed.');
      resolve();
    });

    // good practice to catch this error explicitly
    archive.on('error', (err) => {
      reject(err);
    });

    archive.pipe(output);
    archive.directory(src).finalize();
  })
)
