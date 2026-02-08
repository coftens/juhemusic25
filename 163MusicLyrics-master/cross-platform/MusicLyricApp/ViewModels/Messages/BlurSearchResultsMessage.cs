using CommunityToolkit.Mvvm.Messaging.Messages;

namespace MusicLyricApp.ViewModels.Messages;

public class BlurSearchResultsMessage(string value) : ValueChangedMessage<string>(value);