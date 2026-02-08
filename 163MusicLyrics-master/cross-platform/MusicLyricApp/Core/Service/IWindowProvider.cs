using System.Collections.Generic;
using System.Threading.Tasks;
using Avalonia.Platform.Storage;

namespace MusicLyricApp.Core.Service;

public interface IWindowProvider
{
    Task<IReadOnlyList<IStorageFolder>> OpenFolderPickerAsync(FolderPickerOpenOptions options);
    
    Task SetTextAsync(string? text);
}