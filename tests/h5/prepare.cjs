// Compile the existing HBuilderX project without changing its layout.
const fs = require('fs');
const path = require('path');
const source = path.resolve(__dirname, '../../template/uni-app');
fs.cpSync(source, path.join(__dirname, 'src'), {recursive: true, filter: p => !['node_modules','unpackage'].includes(path.basename(p))});
fs.copyFileSync(path.join(source, 'vue.config.js'), path.join(__dirname, 'vue.config.js'));
