const fs = require('fs');
const zlib = require('zlib');

const inputFile = 'AnimatedSticker.tgs';
const outputFile = 'sticker.json';

const tgsData = fs.readFileSync(inputFile);
const jsonBuffer = zlib.gunzipSync(tgsData);
fs.writeFileSync(outputFile, jsonBuffer);

console.log('Archivo convertido a JSON:', outputFile);
