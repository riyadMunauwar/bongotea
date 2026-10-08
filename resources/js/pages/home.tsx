import { bn } from '@/lang/bn';

/**
 * A stand-in for Home until it is a System Page built from Blocks.
 */
export default function Home() {
    return (
        <section className="shell-container py-10 md:py-[72px]">
            <p className="m-0 max-w-[640px] text-[clamp(18px,2vw,22px)] leading-[1.75] text-muted">{bn.home.comingSoon}</p>
        </section>
    );
}
