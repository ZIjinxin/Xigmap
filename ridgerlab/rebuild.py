"""Build the Xigmap Full installer from the modified XigmaNAS r10566 tree.

Run 'python rebuild.py --help'. The UFS step runs as root on FreeBSD.
The other steps run on Windows, Linux or FreeBSD (Python 3.11+).
"""
import argparse,hashlib,os,shutil,subprocess,tarfile,tempfile
from io import BytesIO
from pathlib import Path, PurePosixPath
SOURCE = Path(__file__).resolve().parent.parent
OFFICIAL = 'efcd57cc4feb9a1fdf7b5130c6de81066617d979f941c207c65302dda76d71fd901bf21eb7e3034b8cb2317876c5a3510f465f5b17366e3c7c1ae8ed4befcd17'
WEB = ('www/login.php','www/index.php','www/favicon.ico','www/images/login_logo.png')
def stage(archive):
    import polib
    po = polib.pofile(str(SOURCE/'locale/xigmanas-zh_CN.po'),encoding='utf-8')
    mo = po.to_binary()
    with tarfile.open(archive,'x') as out:
        for item in WEB:
            out.add(SOURCE/item,arcname=item,recursive=False)
        item=tarfile.TarInfo('share/locale/zh_CN/LC_MESSAGES/xigmanas.mo')
        item.size=len(mo);item.mode=0o644
        out.addfile(item,BytesIO(mo))
def stage_root(archive):
    base=Path('etc/inc/services/samba/share')
    with tarfile.open(archive,'x') as out:
        for name in ('grid_toolbox.php','storage_check.php'):
            path=base/name
            out.add(SOURCE/path,arcname=path.as_posix(),recursive=False)

def extract(official,stage_dir):
    import pycdlib
    with official.open('rb') as fp:
        if hashlib.file_digest(fp,'sha512').hexdigest()!=OFFICIAL:
            raise ValueError('Input does not match the upstream r10566 LiveCD SHA-512')
    stage_dir.mkdir()
    iso=pycdlib.PyCdlib();iso.open(str(official))
    try:
        for folder,dirs,files in iso.walk(rr_path='/'):
            base=PurePosixPath(folder).relative_to('/')
            for subdir in dirs:(stage_dir/base/subdir).mkdir(exist_ok=True,parents=True)
            for file in files:
                if folder=='/' and file=='boot.catalog':continue
                path=stage_dir/base/file
                with path.open('xb') as fp:
                    iso.get_file_from_iso_fp(fp,rr_path=str(PurePosixPath(folder)/file))
    finally:iso.close()
def image(tree,output,tool):
    import pycdlib
    if output.exists():raise FileExistsError(output)
    output=output.resolve()
    with tempfile.TemporaryDirectory(dir=output.parent) as scratch:
        target=Path(scratch)/'ridger.iso'
        # A relative output path also works with the Windows Cygwin build.
        argv=[tool,'-quiet','-r','-J','-V','XigmaNAS-x64-LiveCD-14.3.0.5',
              '-A','Xigmap 1.1.0','-c','boot.catalog','-b','boot/cdboot',
              '-no-emul-boot','-boot-load-size','4','-eltorito-alt-boot',
              '-b','boot/efiboot.img','-no-emul-boot','-o',
              os.path.relpath(target,tree).replace(os.sep,'/'),'.']
        subprocess.run(argv,cwd=tree,check=True)
        iso=pycdlib.PyCdlib();iso.open(str(target))
        try:
            cat=iso.eltorito_boot_catalog
            if cat.initial_entry.sector_count!=4:raise ValueError('Unexpected BIOS sector count')
            lba=iso.get_record(rr_path='/boot.catalog').extent_location()
            efi=iso.get_record(rr_path='/boot/efiboot.img').extent_location()
            if iso.get_record(rr_path='/mdlocal.xz').data_length!=(tree/'mdlocal.xz').stat().st_size:raise ValueError('Bad payload size')
            section_present=bool(cat.sections)
        finally:iso.close()
        # genisoimage 1.1.11 emits two boot records without an EFI section header.
        with target.open('r+b') as fp:
            fp.seek(lba*2048+64)
            header=fp.read(32)
            entry=fp.read(32) if section_present else header
            if entry[0]!=136 or int.from_bytes(entry[8:12],'little')!=efi:raise ValueError('Unexpected EFI boot record')
            if int.from_bytes(entry[6:8],'little')!=16384:raise ValueError('Unexpected EFI image size')
            fp.seek(lba*2048+64);fp.write(bytes([145,239,1,0])+bytes(28)+entry)
        iso=pycdlib.PyCdlib();iso.open(str(target))
        try:
            if iso.eltorito_boot_catalog.sections[0].platform_id!=239:raise ValueError('EFI section is missing')
        finally:iso.close()
        target.replace(output)
if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);sub=p.add_subparsers(dest='action',required=True)
    a=sub.add_parser('stage',help='compile the translation catalogue and archive the 5 changed runtime files')
    a.add_argument('overlay',type=Path)
    a=sub.add_parser('stage-root',help='archive the SMB grid and storage diagnostics for mfsroot.gz')
    a.add_argument('overlay',type=Path)
    a=sub.add_parser('extract',help='verify the official ISO and extract its Rock Ridge filesystem')
    a.add_argument('official',type=Path);a.add_argument('tree',type=Path)
    a=sub.add_parser('image',help='build BIOS/EFI Full installation media after replacing tree/mdlocal.xz')
    a.add_argument('tree',type=Path);a.add_argument('output',type=Path);a.add_argument('--genisoimage',default='genisoimage')
    args=p.parse_args()
    if args.action=='stage':stage(args.overlay)
    elif args.action=='stage-root':stage_root(args.overlay)
    elif args.action=='extract':extract(args.official,args.tree)
    else:image(args.tree,args.output,args.genisoimage)

