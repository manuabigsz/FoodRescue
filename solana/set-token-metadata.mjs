import fs from 'node:fs';
import { createUmi } from '@metaplex-foundation/umi-bundle-defaults';
import {
    createMetadataAccountV3,
    findMetadataPda,
    mplTokenMetadata,
} from '@metaplex-foundation/mpl-token-metadata';
import {
    keypairIdentity,
    percentAmount,
    publicKey,
} from '@metaplex-foundation/umi';

const rpc = process.env.SOLANA_RPC_URL ?? 'https://api.devnet.solana.com';
const mintAddress = process.env.MINT_ADDRESS;
const keypairPath = process.env.MINT_AUTHORITY_KEYPAIR;
const name = process.env.TOKEN_NAME ?? 'FoodRescue Test Dollar';
const symbol = process.env.TOKEN_SYMBOL ?? 'FRUSD';
const uri = process.env.TOKEN_METADATA_URI ?? 'https://example.com/foodrescue-frusd.json';

if (!mintAddress || !keypairPath) {
    console.error('Use MINT_ADDRESS e MINT_AUTHORITY_KEYPAIR.');
    process.exit(2);
}

const secretKey = Uint8Array.from(JSON.parse(fs.readFileSync(keypairPath, 'utf8')));
const umi = createUmi(rpc).use(mplTokenMetadata());
const authority = umi.eddsa.createKeypairFromSecretKey(secretKey);
umi.use(keypairIdentity(authority));

const mint = publicKey(mintAddress);
const metadata = findMetadataPda(umi, { mint });

console.log(`RPC: ${rpc}`);
console.log(`Mint: ${mintAddress}`);
console.log(`Metadata PDA: ${metadata[0]}`);
console.log(`Name: ${name}`);
console.log(`Symbol: ${symbol}`);

const builder = createMetadataAccountV3(umi, {
    metadata,
    mint,
    mintAuthority: authority,
    payer: umi.identity,
    updateAuthority: authority,
    data: {
        name,
        symbol,
        uri,
        sellerFeeBasisPoints: percentAmount(0),
        creators: null,
        collection: null,
        uses: null,
    },
    isMutable: true,
    collectionDetails: null,
});

const result = await builder.sendAndConfirm(umi);
console.log(`Signature: ${result.signature}`);
