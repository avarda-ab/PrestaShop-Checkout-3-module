var path = require('path');
var webpack = require('webpack');
var ExtractTranslationKeysPlugin = require('translations-keys');

module.exports = function(name) {
  var plugins = [
    new ExtractTranslationKeysPlugin({
      functionName: '__',
      output: path.resolve('./build/'+name+'-translation-keys.json')
    }),
    new webpack.DefinePlugin({
      'process.env.NODE_ENV': JSON.stringify("production"),
    })
  ];
  var app = [ name ];

  return {
    module: {
      rules: [
        {
          test: /\.jsx?$/,
          loaders: ['babel-loader'],
          exclude: [ /node_modules/]
        },
        {
          test: /\.css$/,
          loaders: [ 'style-loader', 'css-loader' ],
        },
      ]
    },

    entry: {
      'transl': app
    },

    resolve: {
      modules: ['js', 'node_modules'],
      extensions: ['.js', '.jsx']
    },

    output: {
      filename: '[name].js',
      path: path.resolve('./build/'),
      publicPath: '/'
    },
    stats: {
      warnings: false
    },
    plugins: plugins
  };
};
