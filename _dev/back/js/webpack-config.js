var path = require('path');
var webpack = require('webpack');
var Visualizer = require('webpack-visualizer-plugin');

module.exports = function(prod, version) {
  var plugins = [
    new webpack.ProvidePlugin({
      '__': ['translations', 'getTranslation']
    }),
  ];



  if (! prod) {
    plugins.push(new webpack.DefinePlugin({
      'process.env.NODE_ENV': JSON.stringify("development"),
    }));
  } else {
    plugins.push(new webpack.DefinePlugin({
      'process.env.NODE_ENV': JSON.stringify("production"),
    }));
    plugins.push(new webpack.optimize.ModuleConcatenationPlugin());
    plugins.push(new Visualizer());
  }

  return {
    mode: prod ? 'production' : 'development',
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
      'back': [ "babel-polyfill", "src" ]
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
    plugins: plugins,
    devtool: 'source-map',
    devServer: {
      public: 'prestashop1740.local:8080',
      headers: {
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Headers': 'Origin, X-Requested-With, Content-Type, Accept'
      }
    }
  };
};
