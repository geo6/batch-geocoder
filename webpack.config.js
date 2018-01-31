const webpack = require("webpack");
const path = require("path");

module.exports = {
  entry: {
    "batch-geocoder": "./resources/javascript/main.js"
  },
  output: {
    filename: "[name].min.js",
    path: path.resolve(__dirname, "public/js")
  },
  module: {
    rules: [
      {
        test: /\.css$/,
        use: [
          {loader: "style-loader"},
          {loader: "css-loader"}
        ]
      }
    ]
  },
  plugins: [
    //new webpack.optimize.UglifyJsPlugin()
  ]
};
