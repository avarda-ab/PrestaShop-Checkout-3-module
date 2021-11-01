var gulp = require('gulp');
var gutil = require('gulp-util');
var webpack = require('webpack');
var webpackStream = require('webpack-stream');
var WebpackDevServer = require("webpack-dev-server");
var config = require('./js/webpack-config');
var translateConfig = require('./js/webpack-config-translation');
var del = require('del');
var eslint = require('gulp-eslint');
var zip = require('gulp-zip');
var ramda = require('ramda');
var fs = require('fs');
var path = require('path');
var merge = require('merge-stream');
var replace = require('gulp-replace');
var exec = require('child_process').exec;
var rename = require('gulp-rename');
var license = require('gulp-license-check');
var { find, sortBy, identity, values, mapObjIndexed, append, prepend, map, flatten } = ramda;

function getFolders(dir) {
  return flatten(fs
    .readdirSync(dir)
    .filter(file => fs.statSync(path.join(dir, file)).isDirectory())
    .map(file => {
      const subdirs = getFolders(dir+'/'+file);
      return prepend(dir+'/'+file, subdirs);
    })
  );
}

function getLatestCommitHash(cb) {
  exec('git log -n 1 --pretty=format:"%h"', (err, stdout, stderr) => {
    if (err || !stdout) {
      throw new Error(err);
    }
    cb(stdout);
  });
}

function getVersion() {
  var raw = fs.readFileSync('../../avardapayments.php', 'utf8');
  var lines = map(line => line.replace(/\s+/g, ""), raw.split("\n"));
  var line = find(line => line.indexOf("$this->version") === 0, lines);
  if (line) {
    return line.replace(/[^0-9\.]+/g, '');
  }
  throw new Error("version not found");
}

function readTranslations(path) {
  var keys = JSON.parse(fs.readFileSync(path, 'utf8'));
  return sortBy(identity, values(mapObjIndexed((value, key) => {
    return '"' + key + '" => $this->l(\'' + value.replace(/'/g, "\\'") +'\')';
  }, keys)));
}

gulp.task("devel", function() {
  var compiler = webpack(config(false, getVersion()));
  compiler.hot = true;

  new WebpackDevServer(compiler, {
    contentBase: "./build",
    hot: true,
    quiet: true,
    stats: {colors: true},
    public: 'prestashop1740.local:8080',
  }).listen(8080, "localhost", function(err) {
    if (err)
      throw new gutil.PluginError("webpack-dev-server", err);
  });
});

gulp.task('clean', function(done) {
  del.sync(['./build'], { force: true });
  done();
});

gulp.task('build-javascript', function(done) {
  process.env.NODE_ENV = 'production';
  gulp.src('js')
    .pipe(webpackStream(config(true, getVersion()), webpack))
    //.pipe(gulp.dest('./build'))
    .pipe(gulp.dest('../../views/js/'))
    .on('end', done);
});

gulp.task('create-zip', function(done) {
  var version = getVersion();
  var fileVersion = version.replace(/\./g, '_');
  return gulp
    .src('./build/staging/**/*')
    .pipe(zip('avardapayments-'+fileVersion+'.zip'))
    .pipe(gulp.dest('./build'));
});

gulp.task('copy-text-files', function(done) {
  getLatestCommitHash(commit => {
    const version = getVersion();
    const under = version.replace(/\./g, '_');
    const ext = ['php', 'tpl', 'js', 'sql', 'html', 'md', 'txt', 'css'];
    let sources = map(e => 'php/**/*.'+e, ext);
    sources = append('!php/license-header.*', sources);
    return gulp
      .src(sources)
      .pipe(replace(/CACHE_CONTROL/g, commit))
      .pipe(replace(/CONSTANT_VERSION/g, version))
      .pipe(replace(/CONSTANT_UNDER_VERSION/g, under))
      .pipe(rename(path => path.basename = path.basename.replace(/CONSTANT_UNDER_VERSION/g, under)))
      .pipe(gulp.dest('./build/staging/avardapayments'))
      .on('end', done);
  });
});

gulp.task('copy-binary-files', function(done) {
  const ext = ['png', 'svg', 'jpg'];
  const sources =  map(e => 'php/**/*.'+e, ext);
  return gulp
    .src(sources)
    .pipe(gulp.dest('./build/staging/avardapayments'))
    .on('end', done);
});

gulp.task('copy-build', function(done) {
  const ver = getVersion().replace(/\./g, '_');
  return gulp
    .src(['./build/back.js'])
    .pipe(rename(path => path.basename += '-' + ver))
    .pipe(gulp.dest('./build/staging/avardapayments/views/js'))
    .on('end', done);
});

gulp.task('create-index', function(done) {
  if (!fs.existsSync('./build/staging/avardapayments/translations')){
    fs.mkdirSync('./build/staging/avardapayments/translations');
  }
  var folders = getFolders('./build/staging/avardapayments');
  var tasks = folders.map(folder => gulp.src('php/index.php').pipe(gulp.dest(folder)));
  return merge(tasks);
});

gulp.task('create-config-xml', function(done) {
  const version = getVersion();
  return gulp.src(['php/config.xml.src'])
    .pipe(replace(/CONSTANT_VERSION/g, version))
    .pipe(rename('config.xml'))
    .pipe(gulp.dest('./build/staging/avardapayments'))
    .on('end', done);
});

gulp.task('extract-back-translations', function(done) {
  process.env.NODE_ENV = 'production';
  gulp.src('js')
    .pipe(webpackStream(translateConfig('src'), webpack))
    .pipe(gulp.dest('./build'))
    .on('end', () => {
      del.sync(['./build/transl.js'], { force: true });
      done();
    });
});

gulp.task('create-translations', function(done) {
  const backTranslations = readTranslations('./build/src-translation-keys.json');
  gulp
    .src('php/app-translation.php')
    .pipe(replace(/([ ]*)\/\/ BACK_TRANSLATIONS/, (_, spaces) => {
      return spaces + backTranslations.join(",\n"+spaces);
    }))
    .pipe(gulp.dest('./build/staging/avardapayments/'))
    .on('end', done);
});

gulp.task('merge-translations-keys', function(done) {
  exec('php ./utils/merge-keys.php', (err, stdout, stderr) => {
    if (err) {
      throw new Error(err);
    }
    console.log(stdout.replace(/\s$/g, ""));
    done();
  });
});

gulp.task('translate', gulp.series('extract-back-translations', 'create-translations', 'merge-translations-keys'));

gulp.task('stage', gulp.series('copy-text-files', 'copy-binary-files', 'copy-build', 'create-index', 'create-config-xml', 'translate'));

gulp.task('check-license-php', function() {
  return gulp
    .src(['php/**/*.php', '!php/translations/**'])
    .pipe(license({
      path: 'php/license-header.php',
      blocking: true,
      logInfo: false
    }));
});

gulp.task('check-license-tpl', function(done) {
  return gulp
    .src(['php/**/*.tpl'])
    .pipe(license({
      path: 'php/license-header.tpl',
      blocking: true,
      logInfo: false
    }));
});


gulp.task('eslint', function(done) {
  return gulp.src(['./js/**/*.js', './js/**/*.jsx'])
    .pipe(eslint())
    .pipe(eslint.format())
    .pipe(eslint.failAfterError())
    .pipe(eslint.results(res => {
      if (res.warningCount) {
        throw new gutil.PluginError('gulp-eslint', { message: 'Too many eslint warnings' });
      }
    }))
    .on('end', done);
});

gulp.task('check-license', gulp.series('check-license-php', 'check-license-tpl'));

// gulp.task('release', gulp.series('clean', 'eslint', 'check-license', 'build-javascript', 'stage', 'create-zip'));

gulp.task('release', gulp.series('clean', 'eslint', 'check-license', 'build-javascript'));

gulp.task('default', gulp.series('devel'));
